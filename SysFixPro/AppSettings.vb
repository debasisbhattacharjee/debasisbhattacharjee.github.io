Imports System
Imports System.IO
Imports System.Text.Json
Imports System.Text.Json.Serialization

Namespace SysFixPro

    ''' <summary>Small, real settings file persisted to %LocalAppData%\SysFixPro\settings.json
    ''' so the chosen theme actually survives an app restart.</summary>
    Public Class AppSettings

        Public Property Theme As String = "dark"

        <JsonIgnore>
        Public ReadOnly Property FilePath As String
            Get
                Dim folder = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "SysFixPro")
                Directory.CreateDirectory(folder)
                Return Path.Combine(folder, "settings.json")
            End Get
        End Property

        Public Shared Function Load() As AppSettings
            Dim instance As New AppSettings()
            Try
                Dim path = instance.FilePath
                If File.Exists(path) Then
                    Dim json = File.ReadAllText(path)
                    Dim loaded = JsonSerializer.Deserialize(Of AppSettings)(json)
                    If loaded IsNot Nothing Then Return loaded
                End If
            Catch
            End Try
            Return instance
        End Function

        Public Sub Save()
            Try
                File.WriteAllText(FilePath, JsonSerializer.Serialize(Me))
            Catch
            End Try
        End Sub

    End Class

End Namespace
