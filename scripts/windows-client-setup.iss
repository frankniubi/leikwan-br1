#define MyAppName "LeiKwan Bridge | 利群會聚"
#define MyAppExeName "leikwan-client-win.exe"
#ifndef AppVersion
  #define AppVersion "0.0.0.0"
#endif
#ifndef StageDir
  #error StageDir define is required
#endif
#ifndef OutputDir
  #error OutputDir define is required
#endif

[Setup]
AppId={{D6E7C18D-9C25-4C42-9E9B-1DF4A3F46B90}
AppName={#MyAppName}
AppVersion={#AppVersion}
AppPublisher=LeiKwan
DefaultDirName={autopf}\LeiKwan Bridge
DefaultGroupName=LeiKwan Bridge
AllowNoIcons=yes
OutputDir={#OutputDir}
OutputBaseFilename=leikwan-bridge-setup
SetupIconFile={#SourcePath}\..\LeikwanBridge.Desktop\src\LeikwanBridge.App\Resources\Icons\leikwan-host.ico
Compression=lzma
SolidCompression=yes
PrivilegesRequiredOverridesAllowed=dialog
PrivilegesRequired=admin
ArchitecturesInstallIn64BitMode=x64compatible
WizardStyle=modern
DisableDirPage=no
DisableProgramGroupPage=yes
UninstallDisplayIcon={app}\{#MyAppExeName}
UsePreviousPrivileges=no

[Tasks]
Name: "desktopicon"; Description: "Create Desktop Shortcut"; Flags: unchecked

[Files]
Source: "{#StageDir}\app\*"; DestDir: "{tmp}\app"; Flags: ignoreversion recursesubdirs createallsubdirs deleteafterinstall
Source: "{#StageDir}\windows-client-install-helper.ps1"; DestDir: "{tmp}"; Flags: deleteafterinstall

[UninstallRun]
Filename: "{cmd}"; Parameters: "/c powershell.exe -NoProfile -ExecutionPolicy Bypass -File ""{app}\.installer\windows-client-install-helper.ps1"" -Mode Uninstall -InstallDir ""{app}"""; Flags: runhidden waituntilterminated

[Code]
var
  InstallModePage: TWizardPage;
  AllUsersRadio: TRadioButton;
  CurrentUserRadio: TRadioButton;
  MigrationPage: TWizardPage;
  MigrationInfoLabel: TNewStaticText;
  MigrateRadio: TRadioButton;
  KeepExistingRadio: TRadioButton;
  ExistingInstallDetected: Boolean;
  ExistingInstallDir: string;
  MigrationSelectedDir: string;
  LastSuggestedDir: string;
  InstallHelperExitCode: Integer;
  SilentUpdateRequested: Boolean;
  SilentUpdateInstallDir: string;
  SilentUpdateInstallMode: string;
  SilentUpdateDesktopShortcut: string;

function LegacyPerUserInstallDir: String;
begin
  Result := ExpandConstant('{localappdata}\LeiKwan Bridge');
end;

function NewPerUserInstallDir: String;
begin
  Result := ExpandConstant('{localappdata}\Programs\LeiKwan Bridge');
end;

function MachineInstallDir: String;
begin
  Result := ExpandConstant('{autopf}\LeiKwan Bridge');
end;

function NormalizeInstallDir(const Value: string): string;
begin
  Result := RemoveBackslashUnlessRoot(Trim(Value));
end;

function IsSameInstallDir(const LeftValue, RightValue: string): Boolean;
begin
  Result := CompareText(NormalizeInstallDir(LeftValue), NormalizeInstallDir(RightValue)) = 0;
end;

function DirectoryContainsRealInstall(const CandidateDir: string): Boolean;
begin
  Result := DirExists(CandidateDir) and (
    FileExists(AddBackslash(CandidateDir) + '{#MyAppExeName}') or
    FileExists(AddBackslash(CandidateDir) + 'install-state.json')
  );
end;

function SuggestedDirForCurrentMode: string;
begin
  if AllUsersRadio.Checked then
    Result := MachineInstallDir()
  else
    Result := NewPerUserInstallDir();
end;

procedure ApplyInstallModeSelection;
begin
  if AllUsersRadio.Checked then
    CurrentUserRadio.Checked := False
  else
    CurrentUserRadio.Checked := True;
end;

procedure SetInstallModeForExistingDir(const EffectiveDir: string);
begin
  if IsSameInstallDir(EffectiveDir, MachineInstallDir()) then begin
    AllUsersRadio.Checked := True;
    CurrentUserRadio.Checked := False;
  end else begin
    AllUsersRadio.Checked := False;
    CurrentUserRadio.Checked := True;
  end;

  ApplyInstallModeSelection;
end;

procedure UpdateDefaultDirFromInstallMode;
var
  NewSuggestedDir: string;
begin
  NewSuggestedDir := SuggestedDirForCurrentMode;
  if (Trim(WizardForm.DirEdit.Text) = '') or (CompareText(Trim(WizardForm.DirEdit.Text), LastSuggestedDir) = 0) then
    WizardForm.DirEdit.Text := NewSuggestedDir;
  LastSuggestedDir := NewSuggestedDir;
  ApplyInstallModeSelection;
end;

procedure InstallModeRadioClick(Sender: TObject);
begin
  UpdateDefaultDirFromInstallMode;
end;

function GetSelectedInstallMode: string;
begin
  if WizardSilent then begin
    if IsAdminInstallMode then
      Result := 'all-users'
    else
      Result := 'just-for-me';
    Exit;
  end;

  if Assigned(AllUsersRadio) and AllUsersRadio.Checked then
    Result := 'all-users'
  else
    Result := 'just-for-me';
end;

function GetDesktopShortcutArgValue: string;
begin
  if WizardIsTaskSelected('desktopicon') then
    Result := '1'
  else
    Result := '0';
end;

function GetUpdateRequestedArgValue: string;
begin
  if SilentUpdateRequested then
    Result := '1'
  else
    Result := '0';
end;

function TryExistingInstallCandidate(const CandidateDir, SelectedDir: string): Boolean;
begin
  Result := DirectoryContainsRealInstall(CandidateDir) and (not IsSameInstallDir(CandidateDir, SelectedDir));
  if Result then
    ExistingInstallDir := NormalizeInstallDir(CandidateDir);
end;

function DetectExistingInstallOutsideSelectedDir(const SelectedDir: string): Boolean;
var
  NormalizedSelectedDir: string;
begin
  ExistingInstallDir := '';
  NormalizedSelectedDir := NormalizeInstallDir(SelectedDir);

  if TryExistingInstallCandidate(LegacyPerUserInstallDir(), NormalizedSelectedDir) then
    Result := True
  else if TryExistingInstallCandidate(NewPerUserInstallDir(), NormalizedSelectedDir) then
    Result := True
  else if TryExistingInstallCandidate(MachineInstallDir(), NormalizedSelectedDir) then
    Result := True
  else
    Result := False;
end;

procedure RefreshMigrationDecision;
var
  EffectiveSelectedDir: string;
begin
  EffectiveSelectedDir := NormalizeInstallDir(WizardForm.DirEdit.Text);
  if EffectiveSelectedDir = '' then
    EffectiveSelectedDir := SuggestedDirForCurrentMode;

  MigrationSelectedDir := EffectiveSelectedDir;
  ExistingInstallDetected := DetectExistingInstallOutsideSelectedDir(EffectiveSelectedDir);

  if ExistingInstallDetected then begin
    MigrationInfoLabel.Caption :=
      'LeiKwan Bridge already appears to be installed in:' + #13#10 +
      ExistingInstallDir + #13#10#13#10 +
      'The selected target is:' + #13#10 +
      MigrationSelectedDir + #13#10#13#10 +
      'We recommend migrating program files to the selected target directory by default. ' +
      'Runtime data under %ProgramData%\Leikwan\ClientAgent is preserved.';
    MigrateRadio.Caption := 'Migrate program files to the selected target directory (' + MigrationSelectedDir + ')';
    KeepExistingRadio.Caption := 'Keep the existing install location instead (' + ExistingInstallDir + ')';
    MigrateRadio.Checked := True;
    KeepExistingRadio.Checked := False;
  end;
end;

function ReadCmdParameter(const ParamName: string): string;
var
  I: Integer;
  Prefix: string;
  CurrentParam: string;
begin
  Result := '';
  Prefix := '/' + Uppercase(ParamName) + '=';
  for I := 1 to ParamCount do begin
    CurrentParam := ParamStr(I);
    if Pos(Prefix, Uppercase(CurrentParam)) = 1 then begin
      Result := Copy(CurrentParam, Length(Prefix) + 1, MaxInt);
      Exit;
    end;
  end;
end;

procedure InitializeSilentUpdateParameters;
begin
  SilentUpdateRequested := CompareText(ReadCmdParameter('LEIKWANUPDATE'), '1') = 0;
  SilentUpdateInstallDir := ReadCmdParameter('LEIKWANINSTALLDIR');
  SilentUpdateInstallMode := ReadCmdParameter('LEIKWANINSTALLMODE');
  SilentUpdateDesktopShortcut := ReadCmdParameter('LEIKWANDESKTOPSHORTCUT');
end;

function SkipJsonWhitespace(const JsonText: string; Position: Integer): Integer;
begin
  Result := Position;
  while Result <= Length(JsonText) do begin
    if (Copy(JsonText, Result, 1) = ' ') or (Copy(JsonText, Result, 1) = #9) or
       (Copy(JsonText, Result, 1) = #10) or (Copy(JsonText, Result, 1) = #13) then
      Result := Result + 1
    else
      Exit;
  end;
end;

function FindJsonPropertyValueStart(const JsonText, PropertyName: string): Integer;
var
  Needle: string;
  I: Integer;
begin
  Result := 0;
  Needle := '"' + PropertyName + '"';
  I := Pos(Needle, JsonText);
  if I = 0 then
    Exit;

  I := SkipJsonWhitespace(JsonText, I + Length(Needle));
  if (I > Length(JsonText)) or (Copy(JsonText, I, 1) <> ':') then
    Exit;

  Result := SkipJsonWhitespace(JsonText, I + 1);
end;

function ExtractJsonStringValue(const JsonText, PropertyName: string; var Value: string): Boolean;
var
  I: Integer;
  Ch: string;
  Escaped: Boolean;
begin
  Result := False;
  Value := '';
  I := FindJsonPropertyValueStart(JsonText, PropertyName);
  if (I = 0) or (Copy(JsonText, I, 1) <> '"') then
    Exit;

  I := I + 1;
  Escaped := False;
  while I <= Length(JsonText) do begin
    Ch := Copy(JsonText, I, 1);
    if Escaped then begin
      if Ch = '"' then
        Value := Value + '"'
      else if Ch = '\' then
        Value := Value + '\'
      else if Ch = '/' then
        Value := Value + '/'
      else if Ch = 'n' then
        Value := Value + #10
      else if Ch = 'r' then
        Value := Value + #13
      else if Ch = 't' then
        Value := Value + #9
      else
        Value := Value + Ch;
      Escaped := False;
    end else if Ch = '\' then begin
      Escaped := True;
    end else if Ch = '"' then begin
      Result := True;
      Exit;
    end else begin
      Value := Value + Ch;
    end;
    I := I + 1;
  end;
end;

function ExtractJsonBooleanValue(const JsonText, PropertyName: string; var Value: Boolean): Boolean;
var
  I: Integer;
  Token: string;
begin
  Result := False;
  I := FindJsonPropertyValueStart(JsonText, PropertyName);
  if I = 0 then
    Exit;

  Token := Lowercase(Copy(JsonText, I, 5));
  if Token = 'false' then begin
    Value := False;
    Result := True;
    Exit;
  end;

  Token := Lowercase(Copy(JsonText, I, 4));
  if Token = 'true' then begin
    Value := True;
    Result := True;
  end;
end;

procedure ValidateSilentUpdateExistingInstallState;
var
  StatePath: string;
  StateTextBytes: AnsiString;
  StateText: string;
  DeclaredInstallDir: string;
  ExistingInstallMode: string;
  ExistingDesktopShortcutText: string;
  ExpectedDesktopShortcutText: string;
  ExistingDesktopShortcut: Boolean;
  ExpectedDesktopShortcut: Boolean;
begin
  if not SilentUpdateRequested then
    Exit;

  StatePath := AddBackslash(NormalizeInstallDir(SilentUpdateInstallDir)) + 'install-state.json';
  if not FileExists(StatePath) then
    RaiseException('existing install-state.json missing for silent update');
  if not LoadStringFromFile(StatePath, StateTextBytes) then
    RaiseException('existing install-state.json could not be read');
  StateText := StateTextBytes;

  if (not ExtractJsonStringValue(StateText, 'install_dir', DeclaredInstallDir)) or (Trim(DeclaredInstallDir) = '') then
    RaiseException('existing install-state.json is missing install_dir');
  if not IsSameInstallDir(SilentUpdateInstallDir, DeclaredInstallDir) then
    RaiseException(
      'existing install-state.json install_dir mismatch: expected ' +
      NormalizeInstallDir(SilentUpdateInstallDir) + ' but found ' + NormalizeInstallDir(DeclaredInstallDir));

  if (not ExtractJsonStringValue(StateText, 'install_mode', ExistingInstallMode)) or (Trim(ExistingInstallMode) = '') then
    RaiseException('existing install-state.json is missing install_mode');
  if CompareText(Trim(ExistingInstallMode), Trim(SilentUpdateInstallMode)) <> 0 then
    RaiseException(
      'existing install-state.json install_mode mismatch: expected ' + Trim(SilentUpdateInstallMode) +
      ' but found ' + Trim(ExistingInstallMode));

  if not ExtractJsonBooleanValue(StateText, 'desktop_shortcut', ExistingDesktopShortcut) then
    RaiseException('existing install-state.json is missing desktop_shortcut');
  ExpectedDesktopShortcut := Trim(SilentUpdateDesktopShortcut) = '1';
  if ExistingDesktopShortcut then
    ExistingDesktopShortcutText := '1'
  else
    ExistingDesktopShortcutText := '0';
  if ExpectedDesktopShortcut then
    ExpectedDesktopShortcutText := '1'
  else
    ExpectedDesktopShortcutText := '0';
  if ExistingDesktopShortcut <> ExpectedDesktopShortcut then
    RaiseException(
      'existing install-state.json desktop_shortcut mismatch: expected ' + ExpectedDesktopShortcutText +
      ' but found ' + ExistingDesktopShortcutText);
end;

function GetEffectiveInstallDirForHelper: string;
begin
  if SilentUpdateRequested and (Trim(SilentUpdateInstallDir) <> '') then
    Result := NormalizeInstallDir(SilentUpdateInstallDir)
  else
    Result := NormalizeInstallDir(ExpandConstant('{app}'));
end;

function GetEffectiveInstallModeForHelper: string;
begin
  if SilentUpdateRequested and (Trim(SilentUpdateInstallMode) <> '') then
    Result := Lowercase(Trim(SilentUpdateInstallMode))
  else
    Result := GetSelectedInstallMode();
end;

function GetEffectiveDesktopShortcutArgValue: string;
begin
  if SilentUpdateRequested and (Trim(SilentUpdateDesktopShortcut) <> '') then
    Result := Trim(SilentUpdateDesktopShortcut)
  else
    Result := GetDesktopShortcutArgValue();
end;

function ValidateSilentUpdateParameters: Boolean;
begin
  Result := True;
  if not SilentUpdateRequested then
    Exit;

  if Trim(SilentUpdateInstallDir) = '' then
    RaiseException('LEIKWANINSTALLDIR is required for silent updates');
  if (CompareText(Trim(SilentUpdateInstallMode), 'all-users') <> 0) and (CompareText(Trim(SilentUpdateInstallMode), 'just-for-me') <> 0) then
    RaiseException('LEIKWANINSTALLMODE is required for silent updates');
  if (Trim(SilentUpdateDesktopShortcut) <> '1') and (Trim(SilentUpdateDesktopShortcut) <> '0') then
    RaiseException('LEIKWANDESKTOPSHORTCUT is required for silent updates');
end;

function InitializeSetup: Boolean;
begin
  InitializeSilentUpdateParameters;
  ValidateSilentUpdateParameters;
  ValidateSilentUpdateExistingInstallState;
  Result := True;
end;

function GetHelperCommandLine: string;
var
  TmpHelperPath: string;
begin
  TmpHelperPath := ExpandConstant('{tmp}\windows-client-install-helper.ps1');
  Result := '/c powershell.exe -NoProfile -ExecutionPolicy Bypass -File ' +
    AddQuotes(TmpHelperPath) + ' ' +
    '-InstallDir ' + AddQuotes(GetEffectiveInstallDirForHelper()) + ' ' +
    '-StageDir ' + AddQuotes(ExpandConstant('{tmp}')) + ' ' +
    '-InstallMode ' + AddQuotes(GetEffectiveInstallModeForHelper()) + ' ' +
    '-DesktopShortcut ' + AddQuotes(GetEffectiveDesktopShortcutArgValue()) + ' ' +
    '-DisplayName ' + AddQuotes('{#MyAppName}') + ' ' +
    '-AppVersion ' + AddQuotes(ExpandConstant('{#AppVersion}')) + ' ' +
    '-UpdateRequested ' + AddQuotes(GetUpdateRequestedArgValue());
end;

function RunInstallHelper: Boolean;
var
  ResultCode: Integer;
begin
  Result := Exec(
    ExpandConstant('{cmd}'),
    GetHelperCommandLine(),
    '',
    SW_HIDE,
    ewWaitUntilTerminated,
    ResultCode);

  if Result then
    InstallHelperExitCode := ResultCode
  else
    InstallHelperExitCode := -1;

  if Result and (ResultCode <> 0) then begin
    SuppressibleMsgBox(
      'LeiKwan Bridge install helper failed with exit code ' + IntToStr(ResultCode) + '.',
      mbCriticalError,
      MB_OK,
      IDOK);
    Result := False;
  end else if not Result then begin
    SuppressibleMsgBox(
      'LeiKwan Bridge could not start the install helper.',
      mbCriticalError,
      MB_OK,
      IDOK);
  end;
end;

function NextButtonClick(CurPageID: Integer): Boolean;
begin
  Result := True;

  if Assigned(MigrationPage) and (CurPageID = MigrationPage.ID) then begin
    if ExistingInstallDetected and (not MigrateRadio.Checked) then begin
      WizardForm.DirEdit.Text := ExistingInstallDir;
      SetInstallModeForExistingDir(ExistingInstallDir);
    end else begin
      WizardForm.DirEdit.Text := MigrationSelectedDir;
    end;
  end;
end;

function ShouldSkipPage(PageID: Integer): Boolean;
begin
  Result := False;

  if Assigned(MigrationPage) and (PageID = MigrationPage.ID) then begin
    if WizardSilent then begin
      Result := True;
      Exit;
    end;

    RefreshMigrationDecision;
    Result := not ExistingInstallDetected;
  end;
end;

procedure CurPageChanged(CurPageID: Integer);
begin
  if Assigned(MigrationPage) and (CurPageID = MigrationPage.ID) then
    RefreshMigrationDecision;
end;

procedure CurStepChanged(CurStep: TSetupStep);
begin
  if CurStep = ssPostInstall then begin
    ValidateSilentUpdateParameters;
    if not RunInstallHelper() then
      RaiseException('install helper execution failed');
  end;
end;

procedure InitializeWizard;
begin
  InstallModePage := CreateCustomPage(wpWelcome, 'Install Mode', 'Choose who can use LeiKwan Bridge');

  AllUsersRadio := TRadioButton.Create(WizardForm);
  AllUsersRadio.Parent := InstallModePage.Surface;
  AllUsersRadio.Caption := 'All users';
  AllUsersRadio.Top := ScaleY(16);
  AllUsersRadio.Left := ScaleX(0);
  AllUsersRadio.Width := ScaleX(400);
  AllUsersRadio.OnClick := @InstallModeRadioClick;

  CurrentUserRadio := TRadioButton.Create(WizardForm);
  CurrentUserRadio.Parent := InstallModePage.Surface;
  CurrentUserRadio.Caption := 'Just for me';
  CurrentUserRadio.Top := ScaleY(40);
  CurrentUserRadio.Left := ScaleX(0);
  CurrentUserRadio.Width := ScaleX(400);
  CurrentUserRadio.OnClick := @InstallModeRadioClick;

  MigrationPage := CreateCustomPage(wpSelectTasks, 'Existing Install Detected', 'Choose how to handle the current LeiKwan Bridge install');

  MigrationInfoLabel := TNewStaticText.Create(WizardForm);
  MigrationInfoLabel.Parent := MigrationPage.Surface;
  MigrationInfoLabel.Left := ScaleX(0);
  MigrationInfoLabel.Top := ScaleY(8);
  MigrationInfoLabel.Width := ScaleX(420);
  MigrationInfoLabel.Height := ScaleY(96);
  MigrationInfoLabel.AutoSize := False;
  MigrationInfoLabel.WordWrap := True;

  MigrateRadio := TRadioButton.Create(WizardForm);
  MigrateRadio.Parent := MigrationPage.Surface;
  MigrateRadio.Top := ScaleY(112);
  MigrateRadio.Left := ScaleX(0);
  MigrateRadio.Width := ScaleX(420);

  KeepExistingRadio := TRadioButton.Create(WizardForm);
  KeepExistingRadio.Parent := MigrationPage.Surface;
  KeepExistingRadio.Top := ScaleY(136);
  KeepExistingRadio.Left := ScaleX(0);
  KeepExistingRadio.Width := ScaleX(420);

  AllUsersRadio.Checked := True;
  CurrentUserRadio.Checked := False;

  ExistingInstallDetected := False;
  ExistingInstallDir := '';
  MigrationSelectedDir := '';
  LastSuggestedDir := '';
  InstallHelperExitCode := 0;
  UpdateDefaultDirFromInstallMode;
end;
