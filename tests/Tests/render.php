<?php
class CTag { public $n; public $c=[]; public $cl=[]; public $a=[]; public $pair;
 function __construct($n,$pair=true,$content=null){$this->n=$n;$this->pair=$pair;if($content!==null)$this->addItem($content);}
 function addClass($c){$this->cl[]=$c;return $this;} function setAttribute($k,$v){$this->a[$k]=$v;return $this;}
 function addItem($i){ if(is_array($i)){foreach($i as $x)$this->addItem($x);} elseif($i!==null)$this->c[]=$i; return $this;}
 function __toString(){ $at=''; if($this->cl)$at.=' class="'.htmlspecialchars(implode(' ',$this->cl)).'"';
  foreach($this->a as $k=>$v)$at.=' '.$k.'="'.htmlspecialchars((string)$v).'"';
  if(!$this->pair) return "<{$this->n}$at>"; $in=''; foreach($this->c as $x)$in.=($x instanceof CTag)?(string)$x:htmlspecialchars((string)$x); return "<{$this->n}$at>$in</{$this->n}>"; } }
class CWidgetView { public $items=[]; function __construct($d){} function addItem($i){$this->items[]=$i;return $this;} function show(){foreach($this->items as $i)echo $i;} }
if(!function_exists('mb_strtolower')){function mb_strtolower($s){return strtolower($s);}}
$disk=['label'=>'C:','filesystem'=>'C:','status'=>'warning','used_percent'=>'85.0 %','used_percent_raw'=>85.0];
$server=['hostid'=>'10001','name'=>'SRV-APP01','host_url'=>'x','enabled'=>true,'maintenance'=>false,'ip'=>'10.0.0.5','os'=>'Windows Server 2019','os_family'=>'Windows','agent_ping'=>1,'agent_version'=>'7.0','cpu_raw'=>42.5,'cpu'=>'42.5 %','memory_percent_raw'=>81.0,'memory_percent'=>'81.0 %','memory_used_raw'=>1.0,'memory_total_raw'=>2.0,'memory_total'=>'16 GB','memory_used'=>'13 GB','uptime'=>'3d','uptime_raw'=>259200,'disks'=>[$disk,$disk],'disk_badges'=>[$disk],'hidden_disks'=>1,'network'=>['interfaces'=>[],'total_in'=>'1 MB/s','total_out'=>'2 MB/s','total_in_raw'=>1.0,'total_out_raw'=>2.0,'up_count'=>1,'total_count'=>1],'row_tone'=>'warning','last_update'=>'hoy','item_count'=>50,'zabbix_problems'=>1,'cpu_alert'=>false,'memory_alert'=>true,'disk_alert'=>true,'resource_alerts'=>2,'problems'=>3,'risk_score'=>3000000];
$down=$server; $down['hostid']='10002'; $down['name']='SRV-DB02'; $down['agent_ping']=0; $down['cpu_raw']=null; $down['cpu']='—'; $down['memory_percent_raw']=null; $down['disk_badges']=[]; $down['hidden_disks']=0; $down['disks']=[]; $down['problems']=0; $down['row_tone']='';$down['uptime_raw']=null;$down['cpu_alert']=false;$down['memory_alert']=false;$down['disk_alert']=false;
$data=['name'=>'t','servers'=>[$server,$down],'summary'=>['total'=>2,'up'=>1,'down'=>1,'problems'=>1,'cpu_alerts'=>0,'memory_alerts'=>1,'disk_alerts'=>1,'windows'=>2,'linux'=>0],'rows_per_page'=>25,'disk_warning'=>80,'disk_critical'=>90,'cpu_warning'=>75,'cpu_critical'=>90,'memory_warning'=>80,'memory_critical'=>90,'columns'=>['os','cpu','memory','disk','uptime','network','problems','last_check'],'diagnostic_token'=>'tok','can_scan_ports'=>true,'generated'=>'2026-10-27 17:00:00','user'=>['debug_mode'=>0]];
include ''.__DIR__.'/../server_overview_widget/views/widget.view.php';
