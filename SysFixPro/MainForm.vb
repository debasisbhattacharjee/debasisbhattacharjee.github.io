Imports System
Imports System.Collections.Generic
Imports System.IO
Imports System.Text.Json
Imports System.Threading.Tasks
Imports System.Windows.Forms
Imports Microsoft.Web.WebView2.Core
Imports Microsoft.Web.WebView2.WinForms

Namespace SysFixPro

    Public Class MainForm
        Inherits Form

        Private WithEvents WebView As WebView2
        Private ReadOnly _settings As AppSettings
        Private ReadOnly _wwwroot As String
        Private Shared ReadOnly JsonOptions As New JsonSerializerOptions With {
            .PropertyNamingPolicy = JsonNamingPolicy.CamelCase
        }

        Public Sub New()
            Text = "SysFix Pro - DLL, Runtime & System Repair"
            Width = 1280
            Height = 860
            StartPosition = FormStartPosition.CenterScreen
            MinimumSize = New Drawing.Size(1024, 700)

            _settings = AppSettings.Load()
            _wwwroot = Path.Combine(AppContext.BaseDirectory, "wwwroot")

            WebView = New WebView2() With {
                .Dock = DockStyle.Fill
            }
            Controls.Add(WebView)

            AddHandler Load, AddressOf MainForm_Load
        End Sub

        Private Async Sub MainForm_Load(sender As Object, e As EventArgs)
            Await EnsureWebViewAsync()
        End Sub

        Private Async Function EnsureWebViewAsync() As Task
            Dim userDataFolder = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "SysFixPro", "WebView2")
            Directory.CreateDirectory(userDataFolder)

            Dim env = Await CoreWebView2Environment.CreateAsync(userDataFolder:=userDataFolder)
            Await WebView.EnsureCoreWebView2Async(env)

            WebView.CoreWebView2.SetVirtualHostNameToFolderMapping(
                "sysfixpro.local", _wwwroot, CoreWebView2HostResourceAccessKind.Allow)

            WebView.CoreWebView2.Settings.AreDevToolsEnabled = True
            WebView.CoreWebView2.Settings.IsStatusBarEnabled = False

            AddHandler WebView.CoreWebView2.WebMessageReceived, AddressOf OnWebMessageReceived

            WebView.CoreWebView2.Navigate("https://sysfixpro.local/index.html")
        End Function

        Private Async Sub OnWebMessageReceived(sender As Object, e As CoreWebView2WebMessageReceivedEventArgs)
            Dim raw As String
            Try
                raw = e.TryGetWebMessageAsString()
            Catch
                Return
            End Try

            If String.IsNullOrEmpty(raw) Then Return

            Dim payload As JsonElement
            Try
                Using doc = JsonDocument.Parse(raw)
                    payload = doc.RootElement.Clone()
                End Using
            Catch
                Return
            End Try

            Dim action As String = TryGetString(payload, "action")

            Select Case action
                Case "ready"
                    Await PushSettingsAsync()
                    Await RunScanAsync()

                Case "scan"
                    Await RunScanAsync()

                Case "fix"
                    Dim id = TryGetString(payload, "id")
                    Await RunFixAsync(id)

                Case "repairAll"
                    Await RunRepairAllAsync(payload)

                Case "setTheme"
                    _settings.Theme = TryGetString(payload, "theme")
                    _settings.Save()

                Case "createRestorePoint"
                    Dim outcome = RepairActions.CreateRestorePoint("SysFix Pro maintenance point")
                    Await PostToPageAsync("onBackupResult", outcome)

                Case "openSystemProtection"
                    Dim outcome = RepairActions.OpenSystemProtectionSettings()
                    Await PostToPageAsync("onBackupResult", outcome)

                Case "openManualFolder"
                    Dim outcome = RepairActions.Execute(New DllIssue With {
                        .FixAction = FixActionType.OpenExplorerFolder,
                        .FixTarget = Environment.GetFolderPath(Environment.SpecialFolder.System)
                    })
                    Await PostToPageAsync("onFixResult", New With {outcome.Success, outcome.Message, id = "manual"})
            End Select
        End Sub

        Private Function TryGetString(el As JsonElement, name As String) As String
            Dim prop As JsonElement
            If el.TryGetProperty(name, prop) Then
                Return prop.GetString()
            End If
            Return ""
        End Function

        Private _lastScan As ScanResult

        Private Async Function RunScanAsync() As Task
            _lastScan = Await Task.Run(Function() SystemScanner.RunFullScan())
            Await PostToPageAsync("onScanResult", _lastScan)
        End Function

        Private Async Function RunFixAsync(id As String) As Task
            If _lastScan Is Nothing OrElse _lastScan.Issues Is Nothing Then Return

            Dim issue = _lastScan.Issues.Find(Function(i) i.Id = id)
            If issue Is Nothing Then Return

            Dim outcome = Await Task.Run(Function() RepairActions.Execute(issue))
            Await PostToPageAsync("onFixResult", New With {id, outcome.Success, outcome.Message})
        End Function

        Private Async Function RunRepairAllAsync(payload As JsonElement) As Task
            If _lastScan Is Nothing OrElse _lastScan.Issues Is Nothing Then Return

            Dim ids As New List(Of String)
            Dim idsProp As JsonElement
            If payload.TryGetProperty("ids", idsProp) AndAlso idsProp.ValueKind = JsonValueKind.Array Then
                For Each item In idsProp.EnumerateArray()
                    ids.Add(item.GetString())
                Next
            End If

            Dim seenUrls As New HashSet(Of String)(StringComparer.OrdinalIgnoreCase)

            For Each issue In _lastScan.Issues
                If Not ids.Contains(issue.Id) Then Continue For

                ' Avoid opening the same download page in a dozen browser tabs.
                If issue.FixAction = FixActionType.OpenUrl AndAlso Not seenUrls.Add(issue.FixTarget) Then
                    Await PostToPageAsync("onFixResult", New With {id = issue.Id, success = True, message = "Covered by another fix already opened."})
                    Continue For
                End If

                Dim outcome = Await Task.Run(Function() RepairActions.Execute(issue))
                Await PostToPageAsync("onFixResult", New With {id = issue.Id, outcome.Success, outcome.Message})
            Next
        End Function

        Private Async Function PushSettingsAsync() As Task
            Await PostToPageAsync("onSettings", _settings)
        End Function

        Private Async Function PostToPageAsync(jsFunctionName As String, data As Object) As Task
            Dim json = JsonSerializer.Serialize(data, JsonOptions)
            Dim script = $"window.sysfix && window.sysfix.{jsFunctionName} && window.sysfix.{jsFunctionName}({json});"
            If WebView.CoreWebView2 IsNot Nothing Then
                Await WebView.CoreWebView2.ExecuteScriptAsync(script)
            End If
        End Function

    End Class

End Namespace
