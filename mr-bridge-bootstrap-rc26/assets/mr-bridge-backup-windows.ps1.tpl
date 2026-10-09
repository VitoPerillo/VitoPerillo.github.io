param([switch]$Run,[switch]$ResumeOnly)

$ErrorActionPreference='Stop'
$BaseUrl='__MR_BASE_URL__'
$BootstrapToken='__MR_BACKUP_TOKEN__'
$AppDir=Join-Path $env:LOCALAPPDATA 'MRBridgeBackup'
$Installed=Join-Path $AppDir 'mr-bridge-backup.ps1'
$Config=Join-Path $AppDir 'config.json'
$Task='MR Bridge - Backup settimanale Yoganostress'
$ResumeTask='MR Bridge - Ripresa backup Yoganostress'
$Chunk=4194304
$DbRows=200

function Log($m){$s="[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] $m";Write-Host $s;if(Test-Path $AppDir){Add-Content (Join-Path $AppDir 'backup.log') $s -Encoding UTF8}}
function Protect($t){$b=[Text.Encoding]::UTF8.GetBytes($t);[Convert]::ToBase64String([Security.Cryptography.ProtectedData]::Protect($b,$null,[Security.Cryptography.DataProtectionScope]::CurrentUser))}
function Unprotect($t){$b=[Convert]::FromBase64String($t);[Text.Encoding]::UTF8.GetString([Security.Cryptography.ProtectedData]::Unprotect($b,$null,[Security.Cryptography.DataProtectionScope]::CurrentUser))}
function SaveJson($o,$p){$tmp="$p.tmp";$o|ConvertTo-Json -Depth 12|Set-Content $tmp -Encoding UTF8;Move-Item $tmp $p -Force}
function Retry([scriptblock]$a,$label){$n=0;while($true){try{return & $a}catch{$n++;if($n-ge 12){throw};$d=[Math]::Min(60,[Math]::Pow(2,[Math]::Min($n,5))*2.5);Log "${label}: ritento automaticamente tra $([int]$d)s";Start-Sleep ([int]$d)}}}
function Client($token){$h=New-Object Net.Http.HttpClientHandler;$c=New-Object Net.Http.HttpClient($h);$c.Timeout=[TimeSpan]::FromMinutes(5);$c.DefaultRequestHeaders.Add('X-MR-Backup-Token',$token);$c}
function Header($r,$n){$v=$null;if($r.Headers.TryGetValues($n,[ref]$v)){return($v|Select-Object -First 1)};$v=$null;if($r.Content.Headers.TryGetValues($n,[ref]$v)){return($v|Select-Object -First 1)};''}
function J($c,$p){Retry {$r=$c.GetAsync($script:BaseUrl+$p).Result;if(!$r.IsSuccessStatusCode){throw "HTTP $([int]$r.StatusCode)"};($r.Content.ReadAsStringAsync().Result|ConvertFrom-Json)} "API $p"}
function P($c,$p,$o){Retry {$json=$o|ConvertTo-Json -Depth 12 -Compress;$ct=New-Object Net.Http.StringContent($json,[Text.Encoding]::UTF8,'application/json');try{$r=$c.PostAsync($script:BaseUrl+$p,$ct).Result;if(!$r.IsSuccessStatusCode){throw "HTTP $([int]$r.StatusCode)"};($r.Content.ReadAsStringAsync().Result|ConvertFrom-Json)}finally{$ct.Dispose()}} "API POST $p"}
function B($c,$p){Retry {$r=$c.GetAsync($script:BaseUrl+$p).Result;if(!$r.IsSuccessStatusCode){throw "HTTP $([int]$r.StatusCode)"};[pscustomobject]@{Bytes=$r.Content.ReadAsByteArrayAsync().Result;Sha=(Header $r 'X-MR-SHA256');Size=(Header $r 'X-MR-File-Size');MTime=(Header $r 'X-MR-File-MTime');Rows=(Header $r 'X-MR-Rows')}} "download"}
function Sha($b){$s=[Security.Cryptography.SHA256]::Create();try{([BitConverter]::ToString($s.ComputeHash($b))).Replace('-','').ToLowerInvariant()}finally{$s.Dispose()}}
function Q($s){[Uri]::EscapeDataString($s)}
function Parent($p){$d=Split-Path -Parent $p;if($d -and !(Test-Path $d)){New-Item -ItemType Directory $d -Force|Out-Null}}
function Scan($c,$path,$list){$q=if($path){"?path=$(Q $path)"}else{''};$d=J $c ('/backup/list'+$q);foreach($i in $d.items){if($i.type-eq'dir'){Scan $c ([string]$i.path) $list}elseif($i.type-eq'file'){[void]$list.Add([pscustomobject]@{path=[string]$i.path;size=[int64]$i.size;mtime=[int64]$i.mtime})}}}
function LoadState($p){if(!(Test-Path $p)){return $null};try{Get-Content $p -Raw -Encoding UTF8|ConvertFrom-Json}catch{$null}}
function NewState($dir){[ordered]@{format='mr-bridge-local-backup-v2';status='incomplete';created_at=(Get-Date).ToUniversalTime().ToString('o');backup_dir=$dir;completed_files=@();current_file='';current_file_size=0;current_file_mtime=0;current_file_offset=0;completed_tables=@();current_table='';error=''}}
function FindResume($root){$dirs=Get-ChildItem $root -Directory -Filter 'yoganostress-backup-*' -ErrorAction SilentlyContinue|Sort-Object LastWriteTime -Descending;foreach($d in $dirs){$s=LoadState(Join-Path $d.FullName '.mrbridge-state.json');if($s -and $s.status-ne'complete'){return $d.FullName}};return $null}
function MapFiles($s){$m=@{};foreach($x in @($s.completed_files)){$m[[string]$x.path]=$x};$m}
function MapTables($s){$m=@{};foreach($x in @($s.completed_tables)){$m[[string]$x.table]=$x};$m}

function Site($c,$dir,$s,$sp){
  $files=New-Object Collections.ArrayList;Log 'Scansione file WordPress...';Scan $c '' $files;$done=MapFiles $s;$n=0
  foreach($i in $files){$n++;$dest=Join-Path (Join-Path $dir 'site') (([string]$i.path).Replace('/',[IO.Path]::DirectorySeparatorChar));Parent $dest
    if($done.ContainsKey([string]$i.path)-and(Test-Path $dest)){$saved=$done[[string]$i.path];if((Get-Item $dest).Length-eq[int64]$i.size -and [int64]$saved.size-eq[int64]$i.size -and [int64]$saved.mtime-eq[int64]$i.mtime){continue}}
    $off=0L;if($s.current_file-eq[string]$i.path -and [int64]$s.current_file_size-eq[int64]$i.size -and [int64]$s.current_file_mtime-eq[int64]$i.mtime -and(Test-Path $dest)){$off=[int64]$s.current_file_offset}
    $fs=New-Object IO.FileStream($dest,[IO.FileMode]::OpenOrCreate,[IO.FileAccess]::Write,[IO.FileShare]::Read)
    try{if($fs.Length-gt$off){$fs.SetLength($off)};$fs.Seek($off,[IO.SeekOrigin]::Begin)|Out-Null;$s.current_file=[string]$i.path;$s.current_file_size=[int64]$i.size;$s.current_file_mtime=[int64]$i.mtime;$s.current_file_offset=$off;SaveJson $s $sp
      while($off-lt[int64]$i.size){$len=[Math]::Min($Chunk,[int64]$i.size-$off);$x=B $c ("/backup/file?path=$(Q([string]$i.path))&offset=$off&length=$len");if((Sha $x.Bytes)-ne$x.Sha){throw"SHA file non valido"};if([int64]$x.Size-ne[int64]$i.size -or [int64]$x.MTime-ne[int64]$i.mtime){throw"File cambiato durante backup"};$fs.Write($x.Bytes,0,$x.Bytes.Length);$fs.Flush();$off+=$x.Bytes.Length;$s.current_file_offset=$off;SaveJson $s $sp};$fs.SetLength([int64]$i.size)
    }finally{$fs.Dispose()}
    $s.completed_files+=[pscustomobject]@{path=[string]$i.path;size=[int64]$i.size;mtime=[int64]$i.mtime};$s.current_file='';$s.current_file_offset=0;SaveJson $s $sp;$done[[string]$i.path]=$true;if($n%20-eq0){Log "File $n/$($files.Count) completati"}
  }
}

function Table($c,$tdir,$t){
  $name=[Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($t)).Replace('/','_').Replace('+','-').TrimEnd('=');$out=Join-Path $tdir "$name.sql";$part="$out.part";Remove-Item $part -Force -EA SilentlyContinue
  $a=J $c ("/backup/db/state?table=$(Q $t)");$sch=J $c ("/backup/db/schema?table=$(Q $t)");$tick=[char]96;$safe=$t.Replace("$tick","$tick$tick")
  $w=New-Object IO.StreamWriter($part,$false,(New-Object Text.UTF8Encoding($false)))
  try{$w.WriteLine("DROP TABLE IF EXISTS $tick$safe$tick;");$w.WriteLine(([string]$sch.create_sql)+';');$off=0
    while($true){$x=B $c ("/backup/db/chunk?table=$(Q $t)&offset=$off&limit=$DbRows");if((Sha $x.Bytes)-ne$x.Sha){throw"SHA database non valido"};if($x.Bytes.Length){$w.Flush();$w.BaseStream.Write($x.Bytes,0,$x.Bytes.Length)};$r=[int]$x.Rows;if(!$r){break};$off+=$r};$w.WriteLine();$w.Flush()
  }finally{$w.Dispose()}
  $b=J $c ("/backup/db/state?table=$(Q $t)");$x=$a.state;$y=$b.state;$ok=([int64]$x.rows_exact-eq[int64]$y.rows_exact)-and([string]$x.update_time-eq[string]$y.update_time)-and([int64]$x.data_length-eq[int64]$y.data_length)-and([int64]$x.index_length-eq[int64]$y.index_length)-and([string]$x.checksum-eq[string]$y.checksum);if(!$ok){Remove-Item $part -Force -EA SilentlyContinue;throw"Tabella cambiata: $t"}
  Move-Item $part $out -Force;[pscustomobject]@{table=$t;rows=[int64]$x.rows_exact;schema_sha256=[string]$sch.sha256;file=(Split-Path -Leaf $out)}
}

function Database($c,$dir,$s,$sp){
  $db=Join-Path $dir 'database';$tdir=Join-Path $db 'tables';New-Item -ItemType Directory $tdir -Force|Out-Null;$all=(J $c '/backup/db/tables').tables;$done=MapTables $s;$n=0
  foreach($to in $all){$t=[string]$to;$n++;if($done.ContainsKey($t)-and(Test-Path(Join-Path $tdir ([string]$done[$t].file)))){continue};$s.current_table=$t;SaveJson $s $sp;Log "Database $n/$($all.Count): $t";$e=Table $c $tdir $t;$s.completed_tables+=$e;$s.current_table='';SaveJson $s $sp;$done[$t]=$e}
  $final=Join-Path $db 'database.sql';$w=New-Object IO.StreamWriter($final,$false,(New-Object Text.UTF8Encoding($false)));try{$w.WriteLine('-- MR Bridge Yoganostress database backup');$w.WriteLine('SET NAMES utf8mb4;');$w.WriteLine('SET FOREIGN_KEY_CHECKS=0;');foreach($e in @($s.completed_tables)){Get-Content (Join-Path $tdir ([string]$e.file)) -Encoding UTF8|ForEach-Object{$w.WriteLine($_)}};$w.WriteLine('SET FOREIGN_KEY_CHECKS=1;')}finally{$w.Dispose()}
}

function DoBackup{
  if(!(Test-Path $Config)){throw'Configurazione mancante'};$cfg=Get-Content $Config -Raw -Encoding UTF8|ConvertFrom-Json;$script:BaseUrl=[string]$cfg.BaseUrl;$token=Unprotect([string]$cfg.TokenProtected);$root=[string]$cfg.BackupRoot;New-Item -ItemType Directory $root -Force|Out-Null
  $dir=FindResume $root;if($dir){Log "Ripresa automatica: $dir"}else{if($ResumeOnly){Log 'Nessun backup incompleto da riprendere.';return};$dir=Join-Path $root ("yoganostress-backup-"+(Get-Date -Format 'yyyyMMdd-HHmmss'));New-Item -ItemType Directory (Join-Path $dir 'site') -Force|Out-Null;New-Item -ItemType Directory (Join-Path $dir 'database') -Force|Out-Null}
  $sp=Join-Path $dir '.mrbridge-state.json';$s=LoadState $sp;if(!$s){$s=NewState $dir;SaveJson $s $sp};$c=Client $token
  try{$info=J $c '/backup/info';Log "Backup avviato. Ripresa automatica: $($info.resume_supported)";Site $c $dir $s $sp;Database $c $dir $s $sp;$s.status='complete';$s.completed_at=(Get-Date).ToUniversalTime().ToString('o');$s.error='';SaveJson $s $sp;$manifestPath=Join-Path $dir 'backup-manifest.json';Copy-Item $sp $manifestPath -Force;"Backup WordPress Yoganostress completato e verificato.`nRipresa automatica attiva.`n"|Set-Content(Join-Path $dir 'LEGGIMI.txt') -Encoding UTF8
    try{$manifestBytes=[IO.File]::ReadAllBytes($manifestPath);$manifestSha=Sha $manifestBytes;$fileCount=@($s.completed_files).Count;$fileBytes=0L;foreach($x in @($s.completed_files)){$fileBytes+=[int64]$x.size};$tableCount=@($s.completed_tables).Count;$sqlPath=Join-Path (Join-Path $dir 'database') 'database.sql';$sqlBytes=if(Test-Path $sqlPath){[int64](Get-Item $sqlPath).Length}else{0};$receipt=P $c '/backup/receipt' ([ordered]@{format=[string]$s.format;completed_at=[string]$s.completed_at;manifest_sha256=$manifestSha;files=$fileCount;bytes=$fileBytes;db_tables=$tableCount;db_sql_bytes=$sqlBytes});Log "Ricevuta backup registrata: $($receipt.receipt.receipt_id)"}catch{Log "Backup completo, ma ricevuta server non registrata: $($_.Exception.Message)"}
    Log "BACKUP COMPLETATO E VERIFICATO: $dir"
  }catch{$s.status='incomplete';$s.error=$_.Exception.Message;SaveJson $s $sp;Log "Interrotto: $($_.Exception.Message). Ripresa automatica al prossimo tentativo.";throw}finally{$c.Dispose()}
}

function Install{
  if(!$BootstrapToken){throw'Token bootstrap assente'};New-Item -ItemType Directory $AppDir -Force|Out-Null;Add-Type -AssemblyName System.Windows.Forms;$d=New-Object Windows.Forms.FolderBrowserDialog;$d.Description='Scegli la cartella principale dei backup Yoganostress';if($d.ShowDialog()-ne[Windows.Forms.DialogResult]::OK){throw'Annullato'};$root=$d.SelectedPath
  $day=Read-Host 'Giorno [Sunday = Domenica]';if(!$day){$day='Sunday'};$time=Read-Host 'Ora [20:00]';if(!$time){$time='20:00'}
  [ordered]@{BaseUrl=$BaseUrl;TokenProtected=(Protect $BootstrapToken);BackupRoot=$root;DayOfWeek=$day;Time=$time}|ConvertTo-Json|Set-Content $Config -Encoding UTF8
  $src=Get-Content $MyInvocation.MyCommand.Path -Raw -Encoding UTF8;$needle='$BootstrapToken='+"'"+$BootstrapToken+"'";$replacement='$BootstrapToken='+"''";$src=$src.Replace($needle,$replacement);Set-Content $Installed $src -Encoding UTF8
  $a=New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$Installed`" -Run";$at=[DateTime]::ParseExact($time,'HH:mm',[Globalization.CultureInfo]::InvariantCulture);$tr=New-ScheduledTaskTrigger -Weekly -DaysOfWeek $day -At $at;$set=New-ScheduledTaskSettingsSet -StartWhenAvailable -WakeToRun -ExecutionTimeLimit (New-TimeSpan -Days 2) -RestartCount 48 -RestartInterval (New-TimeSpan -Minutes 15);$userId=[Security.Principal.WindowsIdentity]::GetCurrent().Name;$pr=New-ScheduledTaskPrincipal -UserId $userId -LogonType Interactive -RunLevel Limited;Register-ScheduledTask -TaskName $Task -Action $a -Trigger $tr -Settings $set -Principal $pr -Force|Out-Null
  $ra=New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$Installed`" -Run -ResumeOnly";$rtr=New-ScheduledTaskTrigger -AtLogOn -User $userId;Register-ScheduledTask -TaskName $ResumeTask -Action $ra -Trigger $rtr -Settings $set -Principal $pr -Force|Out-Null
  Write-Host "INSTALLAZIONE COMPLETATA. Backup: $day $time. Se il PC era spento, partira appena possibile.";$now=Read-Host 'Avviare un backup adesso? [S/N]';if($now-match'^[SsYy]'){&$Installed -Run};if($MyInvocation.MyCommand.Path-ne$Installed){try{Remove-Item $MyInvocation.MyCommand.Path -Force}catch{}}
}
if($Run){DoBackup}else{Install}

