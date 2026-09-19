Imports System
Imports System.Collections.Generic
Imports System.Diagnostics
Imports System.IO
Imports System.Management
Imports Microsoft.Win32

Namespace SysFixPro

    ''' <summary>Kinds of remediation SysFixPro is allowed to perform. Every one of these
    ''' either opens an official Microsoft/vendor page or runs a built-in Windows tool -
    ''' nothing is downloaded or executed silently.</summary>
    Public Enum FixActionType
        None
        OpenUrl
        RunSfc
        OpenExplorerFolder
        OpenSettingsPage
    End Enum

    Public Class DllIssue
        Public Property Id As String
        Public Property Name As String
        Public Property Details As String
        Public Property Category As String   ' DllFile, Runtime, DirectX, System, Application
        Public Property Severity As String   ' High, Medium, Low, Info
        Public Property Status As String     ' Missing, Outdated, Recommended, LowSpace
        Public Property FixAction As FixActionType
        Public Property FixTarget As String  ' URL, drive letter, or folder path used by the fix action
    End Class

    Public Class SystemInfoDto
        Public Property OsName As String
        Public Property OsArchitecture As String
        Public Property Cpu As String
        Public Property TotalRamGb As Double
        Public Property Gpu As String
        Public Property PrimaryDrive As String
    End Class

    Public Class ScanResult
        Public Property GeneratedAtUtc As DateTime
        Public Property SystemInfo As SystemInfoDto
        Public Property Issues As List(Of DllIssue)
    End Class

    ''' <summary>
    ''' Performs genuine, live checks against the running machine: registry lookups for
    ''' installed Visual C++ / .NET runtimes, on-disk presence of common redistributable
    ''' DLLs in System32, DirectX 12 availability, free disk space, and basic hardware
    ''' info via WMI. No result here is fabricated or randomised.
    ''' </summary>
    Public Module SystemScanner

        ' Physical redistributable DLLs that are NOT guaranteed to ship with a clean
        ' Windows install. If missing, they are genuinely missing on this machine.
        Private ReadOnly LegacyRedistDlls As (FileName As String, Friendly As String, Category As String)() = {
            ("d3dx9_43.dll", "DirectX 9 component (D3DX9)", "DirectX"),
            ("d3dcompiler_47.dll", "DirectX Shader Compiler", "DirectX"),
            ("xinput1_3.dll", "XInput 1.3 (legacy game controller API)", "DllFile"),
            ("x3daudio1_7.dll", "X3DAudio 1.7", "DllFile"),
            ("xapofx1_5.dll", "XAPOFX 1.5 audio effects", "DllFile"),
            ("msvcp120.dll", "Visual C++ 2013 runtime (MSVCP120)", "Runtime"),
            ("msvcr120.dll", "Visual C++ 2013 runtime (MSVCR120)", "Runtime"),
            ("msvcp110.dll", "Visual C++ 2012 runtime (MSVCP110)", "Runtime"),
            ("msvcr110.dll", "Visual C++ 2012 runtime (MSVCR110)", "Runtime")
        }

        Public Function RunFullScan() As ScanResult
            Dim issues As New List(Of DllIssue)

            issues.AddRange(CheckVcRedist())
            issues.AddRange(CheckLegacyDlls())
            issues.AddRange(CheckDotNetRuntimes())
            issues.AddRange(CheckDirectX12())
            issues.AddRange(CheckDiskSpace())
            issues.AddRange(SystemMaintenanceActions())

            Return New ScanResult With {
                .GeneratedAtUtc = DateTime.UtcNow,
                .SystemInfo = GetSystemInfo(),
                .Issues = issues
            }
        End Function

        ' ---------------------------------------------------------------
        ' Visual C++ Redistributable check (real registry read)
        ' ---------------------------------------------------------------
        Private Function CheckVcRedist() As List(Of DllIssue)
            Dim result As New List(Of DllIssue)

            Dim archTargets = New List(Of (Arch As String, RegPath As String, Url As String)) From {
                ("x64", "SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\X64", "https://aka.ms/vs/17/release/vc_redist.x64.exe"),
                ("x86", "SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\X86", "https://aka.ms/vs/17/release/vc_redist.x86.exe")
            }

            For Each target In archTargets
                Dim installed = False

                For Each viewPath In New String() {target.RegPath, "SOFTWARE\WOW6432Node\Microsoft\VisualStudio\14.0\VC\Runtimes\" & target.Arch}
                    Using key = Registry.LocalMachine.OpenSubKey(viewPath)
                        If key IsNot Nothing Then
                            Dim installedValue = key.GetValue("Installed")
                            If installedValue IsNot Nothing AndAlso Convert.ToInt32(installedValue) = 1 Then
                                installed = True
                            End If
                        End If
                    End Using
                Next

                If Not installed Then
                    result.Add(New DllIssue With {
                        .Id = "vcredist-" & target.Arch,
                        .Name = $"Visual C++ Redistributable ({target.Arch})",
                        .Details = "Not found on this machine. Many games and applications require it to run.",
                        .Category = "Runtime",
                        .Severity = "High",
                        .Status = "Missing",
                        .FixAction = FixActionType.OpenUrl,
                        .FixTarget = target.Url
                    })
                End If
            Next

            Return result
        End Function

        ' ---------------------------------------------------------------
        ' Physical DLL presence check in System32 (real File.Exists)
        ' ---------------------------------------------------------------
        Private Function CheckLegacyDlls() As List(Of DllIssue)
            Dim result As New List(Of DllIssue)
            Dim systemDir = Environment.GetFolderPath(Environment.SpecialFolder.System)

            For Each entry In LegacyRedistDlls
                Dim fullPath = Path.Combine(systemDir, entry.FileName)
                If Not File.Exists(fullPath) Then
                    result.Add(New DllIssue With {
                        .Id = "dll-" & entry.FileName,
                        .Name = entry.FileName,
                        .Details = entry.Friendly & " is not present in " & systemDir,
                        .Category = entry.Category,
                        .Severity = If(entry.Category = "Runtime", "High", "Medium"),
                        .Status = "Missing",
                        .FixAction = FixActionType.OpenUrl,
                        .FixTarget = "https://www.microsoft.com/en-us/download/details.aspx?id=35"
                    })
                End If
            Next

            Return result
        End Function

        ' ---------------------------------------------------------------
        ' .NET runtime check (real `dotnet --list-runtimes` invocation)
        ' ---------------------------------------------------------------
        Private Function CheckDotNetRuntimes() As List(Of DllIssue)
            Dim result As New List(Of DllIssue)
            Dim output = ""
            Dim ranOk = False

            Try
                Using proc As New Process()
                    proc.StartInfo.FileName = "dotnet"
                    proc.StartInfo.Arguments = "--list-runtimes"
                    proc.StartInfo.RedirectStandardOutput = True
                    proc.StartInfo.UseShellExecute = False
                    proc.StartInfo.CreateNoWindow = True
                    proc.Start()
                    output = proc.StandardOutput.ReadToEnd()
                    proc.WaitForExit(5000)
                    ranOk = True
                End Using
            Catch ex As Exception
                ranOk = False
            End Try

            If Not ranOk OrElse String.IsNullOrWhiteSpace(output) Then
                result.Add(New DllIssue With {
                    .Id = "dotnet-runtime-missing",
                    .Name = ".NET Desktop Runtime",
                    .Details = "The 'dotnet' command was not found or returned no installed runtimes.",
                    .Category = "Runtime",
                    .Severity = "Medium",
                    .Status = "Missing",
                    .FixAction = FixActionType.OpenUrl,
                    .FixTarget = "https://dotnet.microsoft.com/en-us/download"
                })
            ElseIf Not output.Contains("Microsoft.WindowsDesktop.App") Then
                result.Add(New DllIssue With {
                    .Id = "dotnet-desktop-missing",
                    .Name = ".NET Desktop Runtime",
                    .Details = "Microsoft.WindowsDesktop.App runtime was not found. Some desktop apps may fail to start.",
                    .Category = "Runtime",
                    .Severity = "Medium",
                    .Status = "Missing",
                    .FixAction = FixActionType.OpenUrl,
                    .FixTarget = "https://dotnet.microsoft.com/en-us/download"
                })
            End If

            Return result
        End Function

        ' ---------------------------------------------------------------
        ' DirectX 12 availability (real file existence check)
        ' ---------------------------------------------------------------
        Private Function CheckDirectX12() As List(Of DllIssue)
            Dim result As New List(Of DllIssue)
            Dim systemDir = Environment.GetFolderPath(Environment.SpecialFolder.System)
            Dim d3d12Path = Path.Combine(systemDir, "d3d12.dll")

            If Not File.Exists(d3d12Path) Then
                result.Add(New DllIssue With {
                    .Id = "directx12-missing",
                    .Name = "DirectX 12 runtime (d3d12.dll)",
                    .Details = "Not found. Install the DirectX End-User Runtime to restore DirectX components.",
                    .Category = "DirectX",
                    .Severity = "High",
                    .Status = "Missing",
                    .FixAction = FixActionType.OpenUrl,
                    .FixTarget = "https://www.microsoft.com/en-us/download/details.aspx?id=35"
                })
            End If

            Return result
        End Function

        ' ---------------------------------------------------------------
        ' Free disk space on fixed drives (real DriveInfo query)
        ' ---------------------------------------------------------------
        Private Function CheckDiskSpace() As List(Of DllIssue)
            Dim result As New List(Of DllIssue)

            For Each drive In DriveInfo.GetDrives()
                If drive.DriveType = DriveType.Fixed AndAlso drive.IsReady Then
                    Dim freeGb = drive.AvailableFreeSpace / 1024.0 / 1024.0 / 1024.0
                    Dim totalGb = drive.TotalSize / 1024.0 / 1024.0 / 1024.0
                    Dim freePercent = If(totalGb > 0, (freeGb / totalGb) * 100.0, 100.0)

                    If freeGb < 5.0 OrElse freePercent < 10.0 Then
                        result.Add(New DllIssue With {
                            .Id = "diskspace-" & drive.Name.Replace(":\", ""),
                            .Name = $"Low disk space on {drive.Name}",
                            .Details = $"Only {freeGb:N1} GB free ({freePercent:N0}%). Low space can cause update and repair failures.",
                            .Category = "System",
                            .Severity = "Medium",
                            .Status = "LowSpace",
                            .FixAction = FixActionType.OpenSettingsPage,
                            .FixTarget = "ms-settings:storagesense"
                        })
                    End If
                End If
            Next

            Return result
        End Function

        ' ---------------------------------------------------------------
        ' Always-available maintenance actions (System / Applications tabs)
        ' ---------------------------------------------------------------
        Private Function SystemMaintenanceActions() As List(Of DllIssue)
            Return New List(Of DllIssue) From {
                New DllIssue With {
                    .Id = "sfc-scan",
                    .Name = "Windows system file check",
                    .Details = "Runs the built-in Windows System File Checker (sfc /scannow) in an elevated window.",
                    .Category = "System",
                    .Severity = "Info",
                    .Status = "Recommended",
                    .FixAction = FixActionType.RunSfc,
                    .FixTarget = ""
                },
                New DllIssue With {
                    .Id = "windows-update",
                    .Name = "Windows Update",
                    .Details = "Opens Windows Update settings so you can install the latest patches and driver updates.",
                    .Category = "System",
                    .Severity = "Info",
                    .Status = "Recommended",
                    .FixAction = FixActionType.OpenSettingsPage,
                    .FixTarget = "ms-settings:windowsupdate"
                }
            }
        End Function

        ' ---------------------------------------------------------------
        ' Real hardware / OS info via WMI + BCL APIs
        ' ---------------------------------------------------------------
        Public Function GetSystemInfo() As SystemInfoDto
            Dim info As New SystemInfoDto With {
                .OsName = "Windows",
                .OsArchitecture = If(Environment.Is64BitOperatingSystem, "64-bit", "32-bit"),
                .Cpu = "Unknown CPU",
                .TotalRamGb = 0,
                .Gpu = "Unknown GPU",
                .PrimaryDrive = "N/A"
            }

            Try
                Using searcher As New ManagementObjectSearcher("SELECT Caption FROM Win32_OperatingSystem")
                    For Each obj As ManagementObject In searcher.Get()
                        info.OsName = Convert.ToString(obj("Caption")).Trim()
                    Next
                End Using
            Catch
            End Try

            Try
                Using searcher As New ManagementObjectSearcher("SELECT Name FROM Win32_Processor")
                    For Each obj As ManagementObject In searcher.Get()
                        info.Cpu = Convert.ToString(obj("Name")).Trim()
                        Exit For
                    Next
                End Using
            Catch
            End Try

            Try
                Using searcher As New ManagementObjectSearcher("SELECT Name FROM Win32_VideoController")
                    Dim names As New List(Of String)
                    For Each obj As ManagementObject In searcher.Get()
                        names.Add(Convert.ToString(obj("Name")).Trim())
                    Next
                    If names.Count > 0 Then info.Gpu = String.Join(" / ", names)
                End Using
            Catch
            End Try

            Try
                info.TotalRamGb = Math.Round(New Microsoft.VisualBasic.Devices.ComputerInfo().TotalPhysicalMemory / 1024.0 / 1024.0 / 1024.0, 1)
            Catch
            End Try

            Try
                Dim systemDrive = Path.GetPathRoot(Environment.GetFolderPath(Environment.SpecialFolder.Windows))
                Dim drive = New DriveInfo(systemDrive)
                info.PrimaryDrive = $"{systemDrive} - {(drive.TotalSize / 1024.0 / 1024.0 / 1024.0):N0} GB total, {(drive.AvailableFreeSpace / 1024.0 / 1024.0 / 1024.0):N0} GB free"
            Catch
            End Try

            Return info
        End Function

    End Module

End Namespace
