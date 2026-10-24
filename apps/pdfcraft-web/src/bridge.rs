//! `window.aiacrobat`: the control channel for the page, as promises.
//!
//! The same JSON methods as the desktop control server (`crates/ui-egui/src/control.rs`), so the
//! assistant panel next to the editor (`studio/`) drives AI-Acrobat the way the CLI does:
//!
//! ```js
//! await aiacrobat.request("ui.state")                  // a control method → its result
//! await aiacrobat.command("page.rotate")               // a registry command (as the menu runs it)
//! await aiacrobat.set("select", "1,3-5")               // a view option or preference
//! const {png_base64} = await aiacrobat.screenshot()    // the window as PNG (base64)
//! aiacrobat.open("договор.pdf", bytes)                 // open a PDF (Uint8Array or ArrayBuffer) in a tab
//! aiacrobat.currentTheme(); await aiacrobat.setTheme("brand") // brand | light | dark | system
//! aiacrobat.wantsKeyboard()                            // a text field has the keyboard
//! ```
//!
//! Results resolve with the method's result; errors reject with an `Error`. Save and Save as
//! reach the page as an `aiacrobat:save` event on `window` (`detail: {name, saveAs, bytes}`), and
//! whether any tab has unsaved work as `aiacrobat:dirty` (`detail: {dirty}`).

use std::cell::RefCell;
use std::sync::mpsc::{Receiver, TryRecvError};

use pdfcraft_ui_egui::control::{ControlClient, Reply};
use pdfcraft_ui_egui::{HostDirtyFn, HostSaveFn, Inbox};
use serde_json::{Value, json};
use eframe::wasm_bindgen::JsCast;
use eframe::wasm_bindgen::prelude::*;

/// The most requests the page may have waiting at once (a runaway script can't fill the memory).
const MAX_WAITING: usize = 256;

/// The largest PDF the page may hand over at once (the app keeps it in memory).
const MAX_OPEN_BYTES: u32 = 1024 * 1024 * 1024;

type Waiting = (Receiver<Reply>, js_sys::Function, js_sys::Function);

thread_local! {
    static CLIENT: RefCell<Option<ControlClient>> = const { RefCell::new(None) };
    static INBOX: RefCell<Option<(Inbox, egui::Context)>> = const { RefCell::new(None) };
    static WAITING: RefCell<Vec<Waiting>> = const { RefCell::new(Vec::new()) };
}

/// Connect the page to the running app: its control client, and the inbox files open from.
pub fn connect(client: ControlClient, inbox: Inbox, ctx: egui::Context) {
    CLIENT.with(|c| *c.borrow_mut() = Some(client));
    INBOX.with(|i| *i.borrow_mut() = Some((inbox, ctx)));
}

fn to_js(v: &Value) -> JsValue {
    js_sys::JSON::parse(&v.to_string()).unwrap_or(JsValue::NULL)
}

fn from_js(v: &JsValue) -> Value {
    if v.is_undefined() || v.is_null() {
        return json!({});
    }
    js_sys::JSON::stringify(v).ok().and_then(|s| s.as_string()).and_then(|s| serde_json::from_str(&s).ok()).unwrap_or(json!({}))
}

/// Settle the promises of answered requests. Runs on a timer: the app answers at the start of a
/// frame, which the request itself asked for.
fn poll_replies() {
    WAITING.with(|w| {
        w.borrow_mut().retain(|(rx, resolve, reject)| match rx.try_recv() {
            Ok(Ok(v)) => {
                let _ = resolve.call1(&JsValue::NULL, &to_js(&v));
                false
            }
            Ok(Err(e)) => {
                let _ = reject.call1(&JsValue::NULL, &js_sys::Error::new(&e));
                false
            }
            Err(TryRecvError::Empty) => true,
            Err(TryRecvError::Disconnected) => {
                let _ = reject.call1(&JsValue::NULL, &js_sys::Error::new("request dropped"));
                false
            }
        });
    });
}

fn request(method: &str, params: Value) -> js_sys::Promise {
    js_sys::Promise::new(&mut |resolve, reject| {
        let Some(client) = CLIENT.with(|c| c.borrow().clone()) else {
            let _ = reject.call1(&JsValue::NULL, &js_sys::Error::new("AI-Acrobat is not running yet"));
            return;
        };
        if WAITING.with(|w| w.borrow().len()) >= MAX_WAITING {
            let _ = reject.call1(&JsValue::NULL, &js_sys::Error::new("too many requests waiting"));
            return;
        }
        let rx = client.send(method, params.clone());
        WAITING.with(|w| w.borrow_mut().push((rx, resolve, reject)));
    })
}

/// Open a PDF the page has (from the server's files): it opens in a tab on the next frame.
fn open(name: String, bytes: JsValue) -> Result<(), JsValue> {
    let array = if let Some(a) = bytes.dyn_ref::<js_sys::Uint8Array>() {
        a.clone()
    } else if bytes.is_instance_of::<js_sys::ArrayBuffer>() {
        js_sys::Uint8Array::new(&bytes)
    } else {
        return Err(js_sys::Error::new("bytes must be a Uint8Array or an ArrayBuffer").into());
    };
    if array.length() > MAX_OPEN_BYTES {
        return Err(js_sys::Error::new("the file is too large").into());
    }
    let name = if name.trim().is_empty() { "document.pdf".to_string() } else { name.chars().take(255).collect() };
    INBOX.with(|i| {
        let guard = i.borrow();
        let Some((inbox, ctx)) = guard.as_ref() else { return Err(js_sys::Error::new("AI-Acrobat is not running yet").into()) };
        if let Ok(mut q) = inbox.lock() {
            q.push((name, array.to_vec()));
        }
        ctx.request_repaint();
        Ok(())
    })
}

fn set(obj: &js_sys::Object, name: &str, f: JsValue) -> Result<(), JsValue> {
    js_sys::Reflect::set(obj, &name.into(), &f).map(|_| ())
}

/// Tell the page something happened (`aiacrobat:<what>` on `window`, with `detail`).
fn notify(what: &str, detail: &JsValue) -> Result<(), String> {
    let window = web_sys::window().ok_or("no window")?;
    let init = web_sys::CustomEventInit::new();
    init.set_detail(detail);
    let event = web_sys::CustomEvent::new_with_event_init_dict(&format!("aiacrobat:{what}"), &init).map_err(|e| format!("{e:?}"))?;
    window.dispatch_event(&event).map(|_| ()).map_err(|e| format!("{e:?}"))
}

/// Saves go to the page (which stores them in the user's files on the server).
pub fn save_fn() -> HostSaveFn {
    Box::new(|name: &str, bytes: &[u8], save_as: bool| {
        let detail = js_sys::Object::new();
        js_sys::Reflect::set(&detail, &"name".into(), &name.into()).map_err(|e| format!("{e:?}"))?;
        js_sys::Reflect::set(&detail, &"saveAs".into(), &save_as.into()).map_err(|e| format!("{e:?}"))?;
        js_sys::Reflect::set(&detail, &"bytes".into(), &js_sys::Uint8Array::from(bytes).into()).map_err(|e| format!("{e:?}"))?;
        notify("save", &detail.into())
    })
}

/// Whether any tab has unsaved work, reported to the page when it changes.
pub fn dirty_fn() -> HostDirtyFn {
    let mut last = false;
    Box::new(move |dirty: bool| {
        if dirty != last {
            last = dirty;
            let _ = notify("dirty", &to_js(&json!({ "dirty": dirty })));
        }
    })
}

/// Install `window.aiacrobat` and the timer that settles its promises.
pub fn install() -> Result<(), JsValue> {
    let obj = js_sys::Object::new();
    set(
        &obj,
        "request",
        Closure::<dyn Fn(String, JsValue) -> js_sys::Promise>::new(|method: String, params: JsValue| request(&method, from_js(&params)))
            .into_js_value(),
    )?;
    set(
        &obj,
        "command",
        Closure::<dyn Fn(String) -> js_sys::Promise>::new(|id: String| request("ui.command", json!({ "id": id }))).into_js_value(),
    )?;
    set(&obj, "commands", Closure::<dyn Fn() -> js_sys::Promise>::new(|| request("ui.commands", json!({}))).into_js_value())?;
    set(&obj, "state", Closure::<dyn Fn() -> js_sys::Promise>::new(|| request("ui.state", json!({}))).into_js_value())?;
    set(
        &obj,
        "set",
        Closure::<dyn Fn(String, JsValue) -> js_sys::Promise>::new(|key: String, value: JsValue| {
            let value = value.as_string().unwrap_or_else(|| from_js(&value).to_string());
            request("ui.set", json!({ "key": key, "value": value }))
        })
        .into_js_value(),
    )?;
    set(&obj, "inspect", Closure::<dyn Fn(JsValue) -> js_sys::Promise>::new(|p: JsValue| request("ui.inspect", from_js(&p))).into_js_value())?;
    set(&obj, "screenshot", Closure::<dyn Fn() -> js_sys::Promise>::new(|| request("ui.screenshot", json!({}))).into_js_value())?;
    set(&obj, "open", Closure::<dyn Fn(String, JsValue) -> Result<(), JsValue>>::new(open).into_js_value())?;
    set(
        &obj,
        "currentTheme",
        Closure::<dyn Fn() -> JsValue>::new(|| {
            let ctx = INBOX.with(|i| i.borrow().as_ref().map(|(_, ctx)| ctx.clone()));
            ctx.map_or(JsValue::NULL, |ctx| {
                let t = pdfcraft_ui_egui::theme::Tokens::get(&ctx);
                let id = if t.dark() && pdfcraft_ui_egui::theme::brand_on() {
                    "brand"
                } else if t.dark() {
                    "dark"
                } else {
                    "light"
                };
                JsValue::from_str(id)
            })
        })
        .into_js_value(),
    )?;
    set(
        &obj,
        "setTheme",
        Closure::<dyn Fn(String) -> js_sys::Promise>::new(|theme: String| {
            let value = match theme.as_str() {
                "light" | "dark" | "system" => theme,
                _ => "brand".to_string(),
            };
            request("ui.set", json!({ "key": "theme", "value": value }))
        })
        .into_js_value(),
    )?;
    set(
        &obj,
        "wantsKeyboard",
        Closure::<dyn Fn() -> bool>::new(|| INBOX.with(|i| i.borrow().as_ref().is_some_and(|(_, ctx)| ctx.text_edit_focused()))).into_js_value(),
    )?;
    set(&obj, "product", JsValue::from_str("AI-Acrobat"))?;
    let w = web_sys::window().ok_or("no window")?;
    js_sys::Reflect::set(&w, &"aiacrobat".into(), &obj)?;
    // Settle answered requests 30 times a second; a cheap check when nothing waits.
    let tick = Closure::<dyn Fn()>::new(poll_replies).into_js_value();
    w.set_interval_with_callback_and_timeout_and_arguments_0(tick.unchecked_ref(), 33)?;
    Ok(())
}
