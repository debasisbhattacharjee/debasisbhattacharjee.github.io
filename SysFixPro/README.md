# SysFix Pro

A Windows Forms desktop app (VB.NET, .NET 8) that hosts its entire UI in an HTML/CSS/JS
page rendered by **WebView2**, styled after modern PC-repair-utility dashboards.

Every result shown on screen comes from a **live check against the machine it runs on** -
there is no random/fake data generator anywhere in this project:

- **Visual C++ Redistributable (x86/x64)** - read from the real
  `HKLM\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes` registry keys.
- **Legacy redistributable DLLs** (`d3dx9_43.dll`, `xinput1_3.dll`, `msvcp120.dll`, ...) -
  checked with `File.Exists` against the real `System32` folder.
- **.NET Desktop Runtime** - detected by actually running `dotnet --list-runtimes`.
- **DirectX 12** - checked via the real presence of `d3d12.dll`.
- **Low disk space** - read from `DriveInfo` for every fixed drive.
- **CPU / GPU / RAM / OS** - queried live via WMI (`System.Management`) and
  `Microsoft.VisualBasic.Devices.ComputerInfo`.

"Fix" never downloads or silently runs unknown code. Each action does one of:

- Opens the **official Microsoft download page** for the missing redistributable/runtime
  in the user's default browser (`vc_redist.x64.exe` via `aka.ms`, the DirectX End-User
  Runtime page, or the .NET download page).
- Runs the **built-in Windows System File Checker** (`sfc /scannow`) in a UAC-elevated
  console window.
- Opens a real **Windows Settings** page (`ms-settings:storagesense`,
  `ms-settings:windowsupdate`).
- Creates a genuine **System Restore point** through the WMI `SystemRestore` class, or
  opens the native System Protection control panel applet.

## Project layout

```
SysFixPro/
  SysFixPro.sln
  SysFixPro.vbproj
  Program.vb            ' entry point
  MainForm.vb            ' hosts the WebView2 control, JS <-> VB.NET message bridge
  SystemScanner.vb       ' all the real, live system checks
  RepairActions.vb       ' all the real remediation actions
  AppSettings.vb         ' persisted theme setting (%LocalAppData%\SysFixPro\settings.json)
  wwwroot/
    index.html            ' UI markup
    style.css             ' visual design (light/dark)
    app.js                ' rendering + WebView2 message bridge (client side)
```

## How the UI <-> app bridge works

1. `MainForm` maps `wwwroot/` to a virtual host (`https://sysfixpro.local/`) with
   `CoreWebView2.SetVirtualHostNameToFolderMapping` and navigates to it - the page is
   loaded from real local files, not embedded strings.
2. `app.js` calls `window.chrome.webview.postMessage(JSON.stringify({ action: ... }))` to
   ask the host to scan, fix an issue, repair everything, switch theme, etc.
3. `MainForm.OnWebMessageReceived` parses that JSON and calls into `SystemScanner` /
   `RepairActions`, then pushes results back into the page by calling
   `window.sysfix.onScanResult(...)` (and friends) through `ExecuteScriptAsync`.

## Building and running (Windows only)

WebView2 and Windows Forms only run on Windows, so this cannot be built or executed in a
Linux sandbox. On a Windows machine with the **.NET 8 SDK** installed:

```powershell
cd SysFixPro
dotnet restore
dotnet build
dotnet run
```

Or open `SysFixPro.sln` in Visual Studio 2022 (Desktop development with .NET workload)
and press F5. The WebView2 runtime itself ships in Windows 10/11 by default; if it's
missing, install the "Evergreen" runtime from
https://developer.microsoft.com/microsoft-edge/webview2/.

Some fixes (`sfc /scannow`, creating a restore point) trigger a UAC prompt or require
System Restore to be enabled on the boot drive - that's expected Windows behavior, not a
bug in the app.
