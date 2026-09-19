Imports System
Imports System.Diagnostics
Imports System.Management

Namespace SysFixPro

    Public Class FixOutcome
        Public Property Success As Boolean
        Public Property Message As String
    End Class

    ''' <summary>
    ''' Carries out the remediation for a DllIssue. Every branch here performs a genuine
    ''' system action - launching the official Microsoft download page in the user's
    ''' default browser, running the built-in System File Checker in an elevated console,
    ''' opening a real Windows Settings page, or creating a real System Restore point via
    ''' WMI. Nothing is faked, and nothing silently downloads or installs third-party code.
    ''' </summary>
    Public Module RepairActions

        Public Function Execute(issue As DllIssue) As FixOutcome
            Try
                Select Case issue.FixAction
                    Case FixActionType.OpenUrl
                        Process.Start(New ProcessStartInfo(issue.FixTarget) With {.UseShellExecute = True})
                        Return New FixOutcome With {.Success = True, .Message = "Opened the official download page in your browser."}

                    Case FixActionType.OpenSettingsPage
                        Process.Start(New ProcessStartInfo(issue.FixTarget) With {.UseShellExecute = True})
                        Return New FixOutcome With {.Success = True, .Message = "Opened Windows Settings."}

                    Case FixActionType.OpenExplorerFolder
                        Process.Start(New ProcessStartInfo("explorer.exe", issue.FixTarget) With {.UseShellExecute = True})
                        Return New FixOutcome With {.Success = True, .Message = "Opened the folder in File Explorer."}

                    Case FixActionType.RunSfc
                        Return RunSystemFileChecker()

                    Case Else
                        Return New FixOutcome With {.Success = False, .Message = "No automated fix is available for this item."}
                End Select
            Catch ex As Exception
                Return New FixOutcome With {.Success = False, .Message = "Could not complete the action: " & ex.Message}
            End Try
        End Function

        Private Function RunSystemFileChecker() As FixOutcome
            Try
                Dim psi As New ProcessStartInfo("cmd.exe", "/k sfc /scannow") With {
                    .UseShellExecute = True,
                    .Verb = "runas"
                }
                Process.Start(psi)
                Return New FixOutcome With {.Success = True, .Message = "Launched an elevated System File Checker scan. Follow the console window."}
            Catch ex As Exception
                Return New FixOutcome With {.Success = False, .Message = "The elevated scan was not started (UAC declined or unavailable): " & ex.Message}
            End Try
        End Function

        ''' <summary>Creates a real Windows System Restore point via WMI, used by the
        ''' Backup &amp; Restore panel. Requires System Restore to be enabled on the boot
        ''' volume; failures are reported honestly rather than reported as success.</summary>
        Public Function CreateRestorePoint(description As String) As FixOutcome
            Try
                Dim scope As New ManagementScope("\\.\root\default")
                scope.Connect()

                Using mc As New ManagementClass(scope, New ManagementPath("SystemRestore"), Nothing)
                    Using inParams = mc.GetMethodParameters("CreateRestorePoint")
                        inParams("Description") = description
                        inParams("RestorePointType") = 12 ' MODIFY_SETTINGS
                        inParams("EventType") = 100        ' BEGIN_SYSTEM_CHANGE

                        Dim outParams = mc.InvokeMethod("CreateRestorePoint", inParams, Nothing)
                        Dim returnValue = Convert.ToUInt32(outParams("ReturnValue"))

                        If returnValue = 0 Then
                            Return New FixOutcome With {.Success = True, .Message = "System Restore point created."}
                        Else
                            Return New FixOutcome With {.Success = False, .Message = $"Windows refused to create a restore point (code {returnValue}). System Restore may be disabled for this drive."}
                        End If
                    End Using
                End Using
            Catch ex As Exception
                Return New FixOutcome With {.Success = False, .Message = "Could not create a restore point: " & ex.Message}
            End Try
        End Function

        Public Function OpenSystemProtectionSettings() As FixOutcome
            Try
                Process.Start(New ProcessStartInfo("SystemPropertiesProtection.exe") With {.UseShellExecute = True})
                Return New FixOutcome With {.Success = True, .Message = "Opened System Protection settings."}
            Catch ex As Exception
                Return New FixOutcome With {.Success = False, .Message = "Could not open System Protection settings: " & ex.Message}
            End Try
        End Function

    End Module

End Namespace
