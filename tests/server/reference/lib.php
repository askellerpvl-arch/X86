<?php
// X86 Stock runtime library for ocStore 3.x
if (!defined('X86STOCK_BOOTSTRAPPED')) {
    define('X86STOCK_BOOTSTRAPPED', 1);
    $root = dirname(__DIR__);
    require_once($root . '/config.php');
    if (!defined('DB_HOSTNAME') || !defined('DB_DATABASE')) {
        http_response_code(500); die('ocStore config.php not found or incomplete');
    }
    $GLOBALS['x86db'] = @new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, defined('DB_PORT') ? DB_PORT : 3306);
    if ($GLOBALS['x86db']->connect_errno) { http_response_code(500); die('Database connection failed'); }
    $GLOBALS['x86db']->set_charset('utf8mb4');
}

function x86db() { return $GLOBALS['x86db']; }
function x86p($name) { return DB_PREFIX . $name; }
function x86_json($data, $status=200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
}
function x86_body() {
    $raw = file_get_contents('php://input');
    if (!$raw) return $_POST;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : $_POST;
}
function x86_setting($key, $default='') {
    $db=x86db(); $table=x86p('x86stock_setting');
    $st=$db->prepare("SELECT value FROM `$table` WHERE `key`=? LIMIT 1"); $st->bind_param('s',$key); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close();
    return $r ? $r['value'] : $default;
}
function x86_same_origin_guard() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    if ($origin) {
        $https=x86_cookie_secure(); $host = ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
        if (rtrim($origin,'/') !== rtrim($host,'/')) x86_json(array('ok'=>false,'error'=>'Forbidden origin'),403);
    }
}
function x86_cookie_secure() { $https=!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'; if(!$https && !empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) $https=strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'])==='https'; return $https; }
function x86_role_signature($token,$role) {
    $secret=(defined('DB_PASSWORD')?DB_PASSWORD:'').'|'.(defined('DB_DATABASE')?DB_DATABASE:'').'|X86STOCK_ROLE_V1';
    return hash_hmac('sha256',(string)$role.'|'.(string)$token,$secret);
}
function x86_set_role_cookie($token,$role,$expires) {
    $value=$role.'.'.x86_role_signature($token,$role);
    setcookie('X86STOCK_ROLE',$value,$expires,'/stock/','',x86_cookie_secure(),true);
}
function x86_role_from_cookie($token) {
    $raw=isset($_COOKIE['X86STOCK_ROLE'])?(string)$_COOKIE['X86STOCK_ROLE']:'';
    if($raw==='') return 'admin'; // Совместимость со старыми административными сессиями.
    $parts=explode('.',$raw,2); if(count($parts)!==2) return false;
    $role=$parts[0]; $sig=$parts[1]; if($role!=='admin'&&$role!=='cashier') return false;
    return hash_equals(x86_role_signature($token,$role),$sig)?$role:false;
}
function x86_auth_user() {
    $token = isset($_COOKIE['X86STOCK_AUTH']) ? $_COOKIE['X86STOCK_AUTH'] : '';
    if (!$token || strlen($token) < 32) return false;
    $hash=hash('sha256',$token); $db=x86db(); $table=x86p('x86stock_auth');
    $st=$db->prepare("SELECT auth_id, user_agent, expires_at FROM `$table` WHERE token_hash=? AND expires_at>NOW() LIMIT 1"); $st->bind_param('s',$hash); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close();
    if(!$r) return false;
    $storedUa=isset($r['user_agent'])?(string)$r['user_agent']:'';
    if(strpos($storedUa,'cashier|')===0) $role='cashier';
    elseif(strpos($storedUa,'admin|')===0) $role='admin';
    else { $role=x86_role_from_cookie($token); if(!$role)$role='admin'; }
    $r['role']=$role; return $r;
}
function x86_require_auth() { $u=x86_auth_user(); if (!$u) x86_json(array('ok'=>false,'error'=>'AUTH_REQUIRED'),401); return $u; }
function x86_require_role($roles) {
    $u=x86_require_auth(); $roles=is_array($roles)?$roles:array($roles);
    if(!in_array(isset($u['role'])?$u['role']:'admin',$roles,true)) x86_json(array('ok'=>false,'error'=>'Недостаточно прав.'),403);
    return $u;
}
function x86_login($login,$password) {
    if (x86_setting('status','1') !== '1') return array(false,'Мобильный склад выключен в настройках.','');
    $login=(string)$login; $password=(string)$password; $role='';
    $adminLogin=x86_setting('mobile_login','admin'); $adminHash=x86_setting('mobile_password_hash','');
    if($adminHash!=='' && hash_equals($adminLogin,$login) && password_verify($password,$adminHash)) {
        $role='admin';
    } else {
        $cashierEnabled=x86_setting('cashier_enabled','0')==='1';
        $cashierLogin=x86_setting('cashier_login','cashier'); $cashierHash=x86_setting('cashier_password_hash','');
        if($cashierEnabled && $cashierHash!=='' && hash_equals($cashierLogin,$login) && password_verify($password,$cashierHash)) $role='cashier';
    }
    if($role==='') {
        if($adminHash==='') return array(false,'Сначала задайте пароль X86 Stock в админке ocStore.','');
        return array(false,'Неверный логин или пароль.','');
    }
    $token=bin2hex(random_bytes(32)); $th=hash('sha256',$token); $rawUa=substr(isset($_SERVER['HTTP_USER_AGENT'])?$_SERVER['HTTP_USER_AGENT']:'',0,245); $ua=$role.'|'.$rawUa; $db=x86db(); $table=x86p('x86stock_auth');
    $st=$db->prepare("INSERT INTO `$table` (token_hash,user_agent,expires_at,date_created) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 180 DAY),NOW())"); $st->bind_param('ss',$th,$ua); $st->execute(); $st->close();
    $expires=time()+180*86400;
    setcookie('X86STOCK_AUTH',$token,$expires,'/stock/','',x86_cookie_secure(),true);
    x86_set_role_cookie($token,$role,$expires);
    return array(true,'OK',$role);
}
function x86_logout() {
    $token=isset($_COOKIE['X86STOCK_AUTH'])?$_COOKIE['X86STOCK_AUTH']:''; if ($token) { $th=hash('sha256',$token); $db=x86db(); $t=x86p('x86stock_auth'); $st=$db->prepare("DELETE FROM `$t` WHERE token_hash=?"); $st->bind_param('s',$th); $st->execute(); $st->close(); }
    setcookie('X86STOCK_AUTH','',time()-3600,'/stock/','',x86_cookie_secure(),true);
    setcookie('X86STOCK_ROLE','',time()-3600,'/stock/','',x86_cookie_secure(),true);
}
function x86_commission_codes($value) {
    $out=array();
    foreach(preg_split('/[,;|]+/',(string)$value) as $code){
        $code=trim($code); if($code!==''&&!in_array($code,$out,true))$out[]=$code;
    }
    return $out;
}
function x86_payment_short_name($code,$name='') {
    $map=array(
        'cash'=>'Наличные',
        'kaspi_gold'=>'Kaspi Gold',
        'kaspi_gold_wallet'=>'Gold Pay',
        'other_bank_card'=>'Другая карта / QR',
        'kaspi_red'=>'Kaspi Red',
        'kaspi_kredit'=>'Kredit',
        'kaspi_qr_12'=>'0-0-12',
        'kaspi_qr_24'=>'0-0-24',
        'kaspi_shop'=>'Магазин'
    );
    $code=trim((string)$code); if(isset($map[$code]))return $map[$code];
    $name=trim((string)$name);
    $name=preg_replace('/\s*[—–-]\s*(Электроника|Компьютеры\/Электроника|QR \/ карта|Apple Pay \/ Google Pay).*$/ui','',$name);
    return $name!==''?$name:$code;
}
function x86_compact_payment_name($labels) {
    $out=array(); foreach((array)$labels as $label){$label=trim((string)$label);if($label!==''&&!in_array($label,$out,true))$out[]=$label;}
    $hasRed=in_array('Kaspi Red',$out,true); $hasKredit=in_array('Kredit',$out,true);
    if($hasRed&&$hasKredit){$out=array_values(array_filter($out,function($v){return $v!=='Kaspi Red'&&$v!=='Kredit';}));array_unshift($out,'Kaspi Red / Kredit');}
    $has12=in_array('0-0-12',$out,true); $hasShop=in_array('Магазин',$out,true);
    if($has12&&$hasShop){$out=array_values(array_filter($out,function($v){return $v!=='0-0-12'&&$v!=='Магазин';}));array_unshift($out,'0-0-12 / Магазин');}
    $hasGold=in_array('Kaspi Gold',$out,true); $hasGoldPay=in_array('Gold Pay',$out,true);
    if($hasGold&&$hasGoldPay){$out=array_values(array_filter($out,function($v){return $v!=='Kaspi Gold'&&$v!=='Gold Pay';}));array_unshift($out,'Kaspi Gold');}
    return implode(' / ',$out);
}
function x86_effective_commissions() {
    $db=x86db(); $t=x86p('x86stock_commission');
    $sql="SELECT commission_id,code,name,rate,notes FROM `$t` WHERE active=1 AND (valid_from IS NULL OR valid_from<=CURDATE()) AND (valid_to IS NULL OR valid_to>=CURDATE()) ORDER BY sort_order,commission_id";
    $r=$db->query($sql); $out=array(); while($row=$r->fetch_assoc()) $out[]=$row; return $out;
}
function x86_commission_by_code($code) {
    $code=trim((string)$code);
    foreach (x86_effective_commissions() as $r) {
        foreach(x86_commission_codes($r['code']) as $alias){
            if($alias===$code){$r['code']=$alias;return $r;}
        }
    }
    return null;
}
function x86_payment_options_compact() {
    $groups=array();
    foreach(x86_effective_commissions() as $c){
        $rate=(float)$c['rate']; $key=number_format($rate,4,'.','');
        if(!isset($groups[$key]))$groups[$key]=array('code'=>'','name'=>'','rate'=>$rate,'labels'=>array(),'codes'=>array());
        foreach(x86_commission_codes($c['code']) as $alias){
            if($groups[$key]['code']==='')$groups[$key]['code']=$alias;
            if(!in_array($alias,$groups[$key]['codes'],true))$groups[$key]['codes'][]=$alias;
            $label=x86_payment_short_name($alias,$c['name']); if($label!==''&&!in_array($label,$groups[$key]['labels'],true))$groups[$key]['labels'][]=$label;
        }
    }
    $out=array(); foreach($groups as $g){$g['name']=x86_compact_payment_name($g['labels']);unset($g['labels']);unset($g['codes']);$out[]=$g;}
    return $out;
}
function x86_payment_is_cash($payment_code='',$payment_name='') {
    $hay=x86_analytics_norm((string)$payment_code.' '.(string)$payment_name);
    return $hay!=='' && (strpos($hay,'налич')!==false || strpos($hay,'cash')!==false);
}
function x86_tax_rate_for_payment($payment_code='',$payment_name='') {
    $rate=(float)x86_setting('tax_rate','3');
    $cashEnabled=x86_setting('tax_cash_enabled','1')==='1';
    if(!$cashEnabled && x86_payment_is_cash($payment_code,$payment_name)) return 0.0;
    return $rate;
}
function x86_language_id() {
    static $id=null; if($id!==null) return $id;
    $db=x86db(); $setting=x86p('setting'); $lang=x86p('language'); $code='';
    $q=$db->query("SELECT value FROM `$setting` WHERE `key`='config_language' ORDER BY store_id ASC LIMIT 1");
    if($q&&($r=$q->fetch_assoc())) $code=trim((string)$r['value']);
    if($code!=='') { $st=$db->prepare("SELECT language_id FROM `$lang` WHERE code=? LIMIT 1"); $st->bind_param('s',$code); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close(); if($r){$id=(int)$r['language_id'];return $id;} }
    $q=$db->query("SELECT language_id FROM `$lang` WHERE status=1 ORDER BY sort_order,language_id LIMIT 1"); if($q&&($r=$q->fetch_assoc())){$id=(int)$r['language_id'];return $id;}
    $id=1; return $id;
}
function x86_stock_statuses() {
    $db=x86db(); $t=x86p('stock_status'); $lid=x86_language_id(); $out=array();
    $st=$db->prepare("SELECT stock_status_id,name FROM `$t` WHERE language_id=? ORDER BY stock_status_id"); $st->bind_param('i',$lid); $st->execute(); $res=$st->get_result(); while($r=$res->fetch_assoc()){$r['stock_status_id']=(int)$r['stock_status_id'];$out[]=$r;} $st->close();
    if(!$out){$q=$db->query("SELECT stock_status_id,MIN(name) name FROM `$t` GROUP BY stock_status_id ORDER BY stock_status_id");if($q)while($r=$q->fetch_assoc()){$r['stock_status_id']=(int)$r['stock_status_id'];$out[]=$r;}}
    return $out;
}
function x86_stock_status_name($id){foreach(x86_stock_statuses() as $s)if((int)$s['stock_status_id']===(int)$id)return $s['name'];return '';}
function x86_stock_status_norm($s){$s=html_entity_decode((string)$s,ENT_QUOTES|ENT_HTML5,'UTF-8');$s=mb_strtolower(trim($s),'UTF-8');$s=str_replace(array('ё','–','—'),array('е','-','-'),$s);$s=preg_replace('/\s+/u',' ',$s);return $s;}
function x86_resolve_stock_status($name){
    $want=x86_stock_status_norm($name); if($want==='под заказ'||$want==='подзаказ')$want='предзаказ'; if($want==='ожидание'||$want==='ожидается')$want='ожидание 2-3 дня';
    $statuses=x86_stock_statuses(); foreach($statuses as $s)if(x86_stock_status_norm($s['name'])===$want)return $s;
    foreach($statuses as $s){$n=x86_stock_status_norm($s['name']);if($want!==''&&(mb_strpos($n,$want,0,'UTF-8')!==false||mb_strpos($want,$n,0,'UTF-8')!==false))return $s;}
    $names=array_map(function($x){return $x['name'];},$statuses); throw new Exception('Статус «'.$name.'» не найден. Доступно: '.implode(', ',$names));
}
function x86_preorder_status_ids(){ $ids=array(); foreach(x86_stock_statuses() as $s){$n=x86_stock_status_norm($s['name']);if(mb_strpos($n,'предзаказ',0,'UTF-8')!==false||$n==='под заказ')$ids[]=(int)$s['stock_status_id'];} return array_values(array_unique($ids)); }
function x86_preorder_sql_condition($alias='p'){ $ids=x86_preorder_status_ids(); return $ids ? $alias.'.stock_status_id NOT IN ('.implode(',',array_map('intval',$ids)).')' : '1=1'; }

function x86_product($product_id) {
    $db=x86db(); $p=x86p('product'); $pd=x86p('product_description'); $xs=x86p('x86stock_product'); $ss=x86p('stock_status'); $lid=x86_language_id();
    $sql="SELECT p.product_id,p.model,p.sku,p.quantity,p.price,p.status,p.stock_status_id,
        (SELECT name FROM `$pd` d WHERE d.product_id=p.product_id ORDER BY (d.language_id=$lid) DESC,language_id ASC LIMIT 1) AS name,
        (SELECT name FROM `$ss` z WHERE z.stock_status_id=p.stock_status_id ORDER BY (z.language_id=$lid) DESC,language_id ASC LIMIT 1) AS stock_status_name,
        xs.barcode,xs.cost_price,xs.partner_price,xs.min_stock,xs.lead_time_days,xs.location
        FROM `$p` p LEFT JOIN `$xs` xs ON xs.product_id=p.product_id WHERE p.product_id=? LIMIT 1";
    $st=$db->prepare($sql); $st->bind_param('i',$product_id); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close();
    if (!$r) return null;
    if (!$r['barcode']) $r['barcode']='X86-' . $r['product_id'];
    $r['stock_status_id']=(int)$r['stock_status_id']; $r['stock_status_name']=$r['stock_status_name']?:x86_stock_status_name($r['stock_status_id']); $r['stock_statuses']=x86_stock_statuses();
    $r['cost_price']=(float)($r['cost_price']?:0); $r['price']=(float)$r['price']; $r['partner_price']=(float)($r['partner_price']?:0); if($r['partner_price']<=0)$r['partner_price']=$r['price'];
    $r['min_stock']=(int)($r['min_stock']!==null?$r['min_stock']:1); $r['lead_time_days']=(int)($r['lead_time_days']!==null?$r['lead_time_days']:(int)x86_setting('default_lead_days','20'));
    $gid=(int)x86_setting('partner_customer_group_id','0');
    if ($r['partner_price']<=0 && $gid>0) {
        $sp=x86p('product_special');
        $q=$db->prepare("SELECT price FROM `$sp` WHERE product_id=? AND customer_group_id=? AND (date_start IS NULL OR date_start='0000-00-00' OR date_start<=CURDATE()) AND (date_end IS NULL OR date_end='0000-00-00' OR date_end>=CURDATE()) ORDER BY priority ASC, price ASC LIMIT 1");
        $q->bind_param('ii',$product_id,$gid); $q->execute(); $s=$q->get_result()->fetch_assoc(); $q->close(); if ($s) $r['partner_price']=(float)$s['price'];
    }
    if ($r['partner_price']<=0) $r['partner_price']=$r['price'];
    $base='';
    if (x86_cookie_secure() && defined('HTTPS_SERVER') && HTTPS_SERVER) $base=HTTPS_SERVER;
    elseif (defined('HTTP_SERVER') && HTTP_SERVER) $base=HTTP_SERVER;
    else $base=(x86_cookie_secure()?'https://':'http://').(isset($_SERVER['HTTP_HOST'])?$_SERVER['HTTP_HOST']:'');
    $r['site_url']=rtrim($base,'/').'/index.php?route=product/product&product_id='.(int)$r['product_id'];
    return $r;
}
function x86_find_product($code) {
    $code=trim((string)$code); if ($code==='') return null;
    if (preg_match('/^X86-?(\d+)$/i',$code,$m)) return x86_product((int)$m[1]);
    if (ctype_digit($code)) { $r=x86_product((int)$code); if ($r) return $r; }
    $db=x86db(); $p=x86p('product'); $xs=x86p('x86stock_product');
    $st=$db->prepare("SELECT p.product_id FROM `$p` p LEFT JOIN `$xs` xs ON xs.product_id=p.product_id WHERE xs.barcode=? OR p.model=? OR p.sku=? LIMIT 1");
    $st->bind_param('sss',$code,$code,$code); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close(); return $r?x86_product((int)$r['product_id']):null;
}
function x86_product_for_role($product,$role='admin') {
    if(!$product || $role!=='cashier') return $product;
    $allowed=array('product_id','model','sku','quantity','price','partner_price','status','stock_status_id','stock_status_name','barcode','name');
    $out=array(); foreach($allowed as $key) if(array_key_exists($key,$product)) $out[$key]=$product[$key];
    return $out;
}
function x86_products_for_role($items,$role='admin') {
    if($role!=='cashier') return $items;
    $out=array(); foreach((array)$items as $item) $out[]=x86_product_for_role($item,$role); return $out;
}
function x86_cashier_sale_items($items,$customer_type) {
    // Кассиру разрешено вручную менять цену в корзине (например, для скидки),
    // но product_id/quantity и сама цена всё равно валидируются сервером в x86_make_sale().
    $clean=array(); foreach((array)$items as $it){
        $pid=isset($it['product_id'])?(int)$it['product_id']:0;
        $qty=isset($it['quantity'])?max(1,(int)$it['quantity']):1;
        if($pid<=0) continue;
        $row=array('product_id'=>$pid,'quantity'=>$qty);
        if(isset($it['unit_price']) && $it['unit_price']!==''){
            $price=(float)$it['unit_price'];
            if($price<0) throw new Exception('Цена не может быть отрицательной.');
            $row['unit_price']=$price;
        }
        $clean[]=$row;
    }
    return $clean;
}
function x86_search_products($q='', $limit=500) {
    $db=x86db(); $p=x86p('product'); $pd=x86p('product_description'); $pa=x86p('product_attribute'); $xs=x86p('x86stock_product'); $ss=x86p('stock_status'); $pc=x86p('product_to_category'); $cd=x86p('category_description'); $ps=x86p('product_special'); $lid=x86_language_id(); $gid=(int)x86_setting('partner_customer_group_id','0');
    // Не обрезаем каталог первыми 50 позициями: пустой поиск показывает весь каталог.
    $q=trim((string)$q); $requested=(int)$limit; $limit=$q===''?max(500,min(1000,$requested)):max(100,min(1000,$requested));
    $like='%'.$q.'%';
    $sql="SELECT p.product_id,p.model,p.sku,p.quantity,p.price,p.stock_status_id,(SELECT name FROM `$pd` d WHERE d.product_id=p.product_id ORDER BY (d.language_id=$lid) DESC,language_id ASC LIMIT 1) name,(SELECT name FROM `$ss` z WHERE z.stock_status_id=p.stock_status_id ORDER BY (z.language_id=$lid) DESC,language_id ASC LIMIT 1) stock_status_name,xs.barcode,xs.cost_price,COALESCE(NULLIF(xs.partner_price,0),(SELECT price FROM `$ps` sp WHERE sp.product_id=p.product_id AND sp.customer_group_id=$gid AND (sp.date_start IS NULL OR sp.date_start='0000-00-00' OR sp.date_start<=CURDATE()) AND (sp.date_end IS NULL OR sp.date_end='0000-00-00' OR sp.date_end>=CURDATE()) ORDER BY sp.priority ASC,sp.price ASC LIMIT 1),p.price) AS partner_price,xs.min_stock,xs.location FROM `$p` p LEFT JOIN `$xs` xs ON xs.product_id=p.product_id WHERE p.status=1 AND (?='' OR p.model LIKE ? OR p.sku LIKE ? OR xs.barcode LIKE ? OR EXISTS(SELECT 1 FROM `$pd` d2 WHERE d2.product_id=p.product_id AND (d2.name LIKE ? OR d2.tag LIKE ? OR d2.description LIKE ?)) OR EXISTS(SELECT 1 FROM `$pa` pa2 WHERE pa2.product_id=p.product_id AND pa2.text LIKE ?) OR EXISTS(SELECT 1 FROM `$pc` pc2 JOIN `$cd` cd2 ON cd2.category_id=pc2.category_id WHERE pc2.product_id=p.product_id AND cd2.name LIKE ?)) ORDER BY p.product_id DESC LIMIT $limit";
    $st=$db->prepare($sql); $st->bind_param('sssssssss',$q,$like,$like,$like,$like,$like,$like,$like,$like); $st->execute(); $res=$st->get_result(); $out=array(); while($r=$res->fetch_assoc()){ $r['barcode']=$r['barcode']?:'X86-'.$r['product_id']; $r['cost_price']=(float)($r['cost_price']?:0); $r['price']=(float)$r['price']; $r['partner_price']=(float)($r['partner_price']?:0); if($r['partner_price']<=0)$r['partner_price']=$r['price']; $r['stock_status_id']=(int)$r['stock_status_id']; $r['stock_status_name']=$r['stock_status_name']?:x86_stock_status_name($r['stock_status_id']); $out[]=$r; } $st->close(); return $out;
}
function x86_product_categories($limit=60) {
    $db=x86db(); $p=x86p('product'); $pc=x86p('product_to_category'); $cd=x86p('category_description'); $lid=x86_language_id(); $limit=max(1,min(100,(int)$limit));
    $sql="SELECT c.category_id,c.name,COUNT(DISTINCT pc.product_id) product_count FROM `$cd` c JOIN `$pc` pc ON pc.category_id=c.category_id JOIN `$p` p ON p.product_id=pc.product_id WHERE p.status=1 AND c.language_id=? GROUP BY c.category_id,c.name HAVING product_count>0 ORDER BY product_count DESC,c.name ASC LIMIT $limit";
    $st=$db->prepare($sql); $st->bind_param('i',$lid); $st->execute(); $res=$st->get_result(); $out=array(); while($r=$res->fetch_assoc()){ $r['category_id']=(int)$r['category_id']; $r['product_count']=(int)$r['product_count']; $out[]=$r; } $st->close(); return $out;
}

function x86_upsert_product_meta($product_id,$fields) {
    $db=x86db(); $t=x86p('x86stock_product'); $cur=x86_product($product_id); if(!$cur) return false;
    $barcode=isset($fields['barcode'])&&trim($fields['barcode'])!==''?trim($fields['barcode']):$cur['barcode'];
    $cost=isset($fields['cost_price'])?(float)$fields['cost_price']:$cur['cost_price']; $partner=isset($fields['partner_price'])?(float)$fields['partner_price']:$cur['partner_price'];
    $min=isset($fields['min_stock'])?(int)$fields['min_stock']:$cur['min_stock']; $lead=isset($fields['lead_time_days'])?(int)$fields['lead_time_days']:$cur['lead_time_days']; $loc=isset($fields['location'])?trim($fields['location']):($cur['location']?:'');
    $st=$db->prepare("INSERT INTO `$t` (product_id,barcode,cost_price,partner_price,min_stock,lead_time_days,location,updated_at) VALUES (?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE barcode=VALUES(barcode),cost_price=VALUES(cost_price),partner_price=VALUES(partner_price),min_stock=VALUES(min_stock),lead_time_days=VALUES(lead_time_days),location=VALUES(location),updated_at=NOW()");
    $st->bind_param('isddiis',$product_id,$barcode,$cost,$partner,$min,$lead,$loc); $ok=$st->execute(); $st->close(); return $ok;
}

function x86_save_product($product_id,$fields) {
    $product_id=(int)$product_id;
    $cur=x86_product($product_id); if(!$cur) throw new Exception('Товар не найден.');
    $price=isset($fields['price'])?(float)$fields['price']:(float)$cur['price'];
    $quantity=isset($fields['quantity'])?(int)$fields['quantity']:(int)$cur['quantity'];
    $cost=isset($fields['cost_price'])?(float)$fields['cost_price']:(float)$cur['cost_price'];
    $partner=isset($fields['partner_price'])?(float)$fields['partner_price']:(float)$cur['partner_price'];
    $min=isset($fields['min_stock'])?(int)$fields['min_stock']:(int)$cur['min_stock'];
    $lead=isset($fields['lead_time_days'])?(int)$fields['lead_time_days']:(int)$cur['lead_time_days'];
    $barcode=isset($fields['barcode'])&&trim((string)$fields['barcode'])!==''?trim((string)$fields['barcode']):(string)$cur['barcode'];
    $loc=isset($fields['location'])?trim((string)$fields['location']):(string)($cur['location']?:'');
    $stockStatusId=isset($fields['stock_status_id'])?(int)$fields['stock_status_id']:(int)$cur['stock_status_id'];
    $valid=false; foreach(x86_stock_statuses() as $ss) if((int)$ss['stock_status_id']===$stockStatusId){$valid=true;break;} if(!$valid) throw new Exception('Неверный статус наличия.');
    if($price<0||$quantity<0||$cost<0||$partner<0||$min<0||$lead<0) throw new Exception('Цена, количество и складские параметры не могут быть отрицательными.');
    $db=x86db(); $pt=x86p('product'); $xt=x86p('x86stock_product'); $mv=x86p('x86stock_movement');
    $db->begin_transaction();
    try {
        $st=$db->prepare("SELECT quantity FROM `$pt` WHERE product_id=? FOR UPDATE"); $st->bind_param('i',$product_id); $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close(); if(!$row) throw new Exception('Товар не найден.'); $before=(int)$row['quantity'];
        $st=$db->prepare("UPDATE `$pt` SET price=?, quantity=?, stock_status_id=?, date_modified=NOW() WHERE product_id=?"); $st->bind_param('diii',$price,$quantity,$stockStatusId,$product_id); $st->execute(); $st->close();
        $st=$db->prepare("INSERT INTO `$xt` (product_id,barcode,cost_price,partner_price,min_stock,lead_time_days,location,updated_at) VALUES (?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE barcode=VALUES(barcode),cost_price=VALUES(cost_price),partner_price=VALUES(partner_price),min_stock=VALUES(min_stock),lead_time_days=VALUES(lead_time_days),location=VALUES(location),updated_at=NOW()");
        $st->bind_param('isddiis',$product_id,$barcode,$cost,$partner,$min,$lead,$loc); $st->execute(); $st->close();
        if($quantity!==$before){ $delta=$quantity-$before; $type='inventory'; $ref=0; $note='Редактирование карточки товара с телефона'; $st=$db->prepare("INSERT INTO `$mv` (product_id,type,reference_id,quantity_delta,qty_before,qty_after,unit_cost,note,date_created) VALUES (?,?,?,?,?,?,?,?,NOW())"); $st->bind_param('isiiiids',$product_id,$type,$ref,$delta,$before,$quantity,$cost,$note); $st->execute(); $st->close(); }
        $db->commit(); return x86_product($product_id);
    } catch(Throwable $e){ $db->rollback(); throw $e; }
}
function x86_receive($product_id,$qty,$unit_cost,$supplier='',$note='') {
    $qty=(int)$qty; $unit_cost=(float)$unit_cost; if($qty<=0||$unit_cost<0) throw new Exception('Неверное количество или закупочная цена.');
    $db=x86db(); $p=x86p('product'); $xs=x86p('x86stock_product'); $ph=x86p('x86stock_purchase'); $pi=x86p('x86stock_purchase_item'); $mv=x86p('x86stock_movement');
    $db->begin_transaction();
    try {
        $st=$db->prepare("SELECT quantity FROM `$p` WHERE product_id=? FOR UPDATE"); $st->bind_param('i',$product_id); $st->execute(); $rr=$st->get_result()->fetch_assoc(); $st->close(); if(!$rr) throw new Exception('Товар не найден.'); $before=(int)$rr['quantity'];
        $meta=x86_product($product_id); $old_cost=(float)$meta['cost_price']; $after=$before+$qty; $new_cost=$after>0?(($before*$old_cost)+($qty*$unit_cost))/$after:$unit_cost;
        x86_upsert_product_meta($product_id,array('cost_price'=>$new_cost));
        $st=$db->prepare("UPDATE `$p` SET quantity=? WHERE product_id=?"); $st->bind_param('ii',$after,$product_id); $st->execute(); $st->close();
        $total=$qty*$unit_cost; $st=$db->prepare("INSERT INTO `$ph` (supplier,note,total,date_created) VALUES (?,?,?,NOW())"); $st->bind_param('ssd',$supplier,$note,$total); $st->execute(); $purchase_id=$db->insert_id; $st->close();
        $st=$db->prepare("INSERT INTO `$pi` (purchase_id,product_id,quantity,unit_cost,old_cost,new_avg_cost) VALUES (?,?,?,?,?,?)"); $st->bind_param('iiiddd',$purchase_id,$product_id,$qty,$unit_cost,$old_cost,$new_cost); $st->execute(); $st->close();
        $type='purchase'; $st=$db->prepare("INSERT INTO `$mv` (product_id,type,reference_id,quantity_delta,qty_before,qty_after,unit_cost,note,date_created) VALUES (?,?,?,?,?,?,?,?,NOW())"); $st->bind_param('isiiiids',$product_id,$type,$purchase_id,$qty,$before,$after,$unit_cost,$note); $st->execute(); $st->close();
        $db->commit(); return array('purchase_id'=>$purchase_id,'qty_after'=>$after,'new_avg_cost'=>round($new_cost,4));
    } catch(Throwable $e){ $db->rollback(); throw $e; }
}
function x86_adjust_stock($product_id,$new_qty,$note='Инвентаризация') {
    $new_qty=(int)$new_qty; if($new_qty<0) throw new Exception('Остаток не может быть отрицательным.'); $db=x86db(); $p=x86p('product'); $mv=x86p('x86stock_movement');
    $db->begin_transaction(); try { $st=$db->prepare("SELECT quantity FROM `$p` WHERE product_id=? FOR UPDATE"); $st->bind_param('i',$product_id); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close(); if(!$r) throw new Exception('Товар не найден.'); $before=(int)$r['quantity']; $delta=$new_qty-$before; $st=$db->prepare("UPDATE `$p` SET quantity=? WHERE product_id=?"); $st->bind_param('ii',$new_qty,$product_id); $st->execute(); $st->close(); $meta=x86_product($product_id); $type='inventory'; $ref=0; $cost=(float)$meta['cost_price']; $st=$db->prepare("INSERT INTO `$mv` (product_id,type,reference_id,quantity_delta,qty_before,qty_after,unit_cost,note,date_created) VALUES (?,?,?,?,?,?,?,?,NOW())"); $st->bind_param('isiiiids',$product_id,$type,$ref,$delta,$before,$new_qty,$cost,$note); $st->execute(); $st->close(); $db->commit(); return array('qty_before'=>$before,'qty_after'=>$new_qty,'delta'=>$delta); } catch(Throwable $e){$db->rollback(); throw $e;}
}
function x86_make_sale($items,$payment_code,$customer_type='retail',$note='') {
    if(!is_array($items)||!count($items)) throw new Exception('Корзина пуста.'); $comm=x86_commission_by_code($payment_code); if(!$comm) throw new Exception('Способ оплаты недоступен.');
    $tax_rate=x86_tax_rate_for_payment($comm['code'],$comm['name']); $rate=(float)$comm['rate']; $db=x86db(); $p=x86p('product'); $sale=x86p('x86stock_sale'); $si=x86p('x86stock_sale_item'); $mv=x86p('x86stock_movement');
    $db->begin_transaction(); try {
        $prepared=array(); $gross=$commission_total=$tax_total=$cost_total=$profit_total=0.0;
        foreach($items as $it){ $pid=(int)$it['product_id']; $qty=max(1,(int)$it['quantity']); $meta=x86_product($pid); if(!$meta) throw new Exception('Товар #'.$pid.' не найден.'); $st=$db->prepare("SELECT quantity FROM `$p` WHERE product_id=? FOR UPDATE"); $st->bind_param('i',$pid); $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close(); if(!$row||$row['quantity']<$qty) throw new Exception($meta['name'].': недостаточно товара. Остаток '.$row['quantity']);
            $default_price=$customer_type==='partner'?(float)$meta['partner_price']:(float)$meta['price']; $price=isset($it['unit_price'])?(float)$it['unit_price']:$default_price; if($price<0) throw new Exception('Цена не может быть отрицательной.'); $line=$price*$qty; $commission=round($line*$rate/100,2); $tax=round($line*$tax_rate/100,2); $cost=(float)$meta['cost_price']*$qty; $profit=$line-$commission-$tax-$cost; $prepared[]=array($meta,$qty,$price,$line,$commission,$tax,$cost,$profit,(int)$row['quantity']); $gross+=$line; $commission_total+=$commission; $tax_total+=$tax; $cost_total+=$cost; $profit_total+=$profit; }
        $pname=$comm['name']; $st=$db->prepare("INSERT INTO `$sale` (customer_type,payment_code,payment_name,commission_rate,tax_rate,gross_total,commission_total,tax_total,cost_total,profit_total,note,date_created) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())"); $st->bind_param('sssddddddds',$customer_type,$payment_code,$pname,$rate,$tax_rate,$gross,$commission_total,$tax_total,$cost_total,$profit_total,$note); $st->execute(); $sale_id=$db->insert_id; $st->close();
        foreach($prepared as $x){ list($meta,$qty,$price,$line,$commission,$tax,$cost,$profit,$before)=$x; $after=$before-$qty; $st=$db->prepare("UPDATE `$p` SET quantity=? WHERE product_id=?"); $st->bind_param('ii',$after,$meta['product_id']); $st->execute(); $st->close(); $unit_cost=(float)$meta['cost_price']; $st=$db->prepare("INSERT INTO `$si` (sale_id,product_id,name,model,quantity,unit_price,unit_cost,line_total,commission_amount,tax_amount,profit_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?)"); $st->bind_param('iissidddddd',$sale_id,$meta['product_id'],$meta['name'],$meta['model'],$qty,$price,$unit_cost,$line,$commission,$tax,$profit); $st->execute(); $st->close(); $delta=-$qty; $type='sale'; $note2='Продажа #'.$sale_id; $st=$db->prepare("INSERT INTO `$mv` (product_id,type,reference_id,quantity_delta,qty_before,qty_after,unit_cost,note,date_created) VALUES (?,?,?,?,?,?,?,?,NOW())"); $st->bind_param('isiiiids',$meta['product_id'],$type,$sale_id,$delta,$before,$after,$unit_cost,$note2); $st->execute(); $st->close(); }
        $db->commit(); return array('sale_id'=>$sale_id,'gross_total'=>round($gross,2),'commission_total'=>round($commission_total,2),'tax_total'=>round($tax_total,2),'cost_total'=>round($cost_total,2),'profit_total'=>round($profit_total,2));
    } catch(Throwable $e){$db->rollback(); throw $e;}
}

function x86_ocstore_order_statuses() {
    $db=x86db(); $t=x86p('setting'); $ids=array();
    foreach(array('config_processing_status','config_complete_status') as $key){
        $st=$db->prepare("SELECT value FROM `$t` WHERE `key`=? ORDER BY store_id ASC LIMIT 1"); $st->bind_param('s',$key); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close();
        if(!$r) continue; $v=$r['value']; $a=json_decode($v,true); if(!is_array($a)){ $u=@unserialize($v); if(is_array($u)) $a=$u; }
        if(is_array($a)) foreach($a as $id) if((int)$id>0) $ids[]=(int)$id;
    }
    return array_values(array_unique($ids));
}
function x86_ocstore_status_sql() { $ids=x86_ocstore_order_statuses(); return $ids?implode(',',array_map('intval',$ids)):'0'; }

function x86_returns_table() { return x86p('x86stock_return'); }
function x86_ensure_returns_table() {
    static $done=false; if($done) return; $done=true; $db=x86db(); $t=x86_returns_table();
    $db->query("CREATE TABLE IF NOT EXISTS `$t` (return_id INT UNSIGNED NOT NULL AUTO_INCREMENT,source VARCHAR(16) NOT NULL,reference_id INT NOT NULL,reason VARCHAR(255) NOT NULL DEFAULT '',stock_restored TINYINT(1) NOT NULL DEFAULT 0,date_created DATETIME NOT NULL,PRIMARY KEY(return_id),UNIQUE KEY source_reference(source,reference_id),KEY date_created(date_created)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function x86_return_stock_sale($sale_id,$reason='Возврат') {
    $sale_id=(int)$sale_id; if($sale_id<=0) throw new Exception('Неверный номер продажи.'); x86_ensure_returns_table();
    $db=x86db(); $rt=x86_returns_table(); $s=x86p('x86stock_sale'); $si=x86p('x86stock_sale_item'); $p=x86p('product'); $mv=x86p('x86stock_movement');
    $db->begin_transaction();
    try {
        $st=$db->prepare("SELECT return_id FROM `$rt` WHERE source='stock' AND reference_id=? LIMIT 1 FOR UPDATE"); $st->bind_param('i',$sale_id); $st->execute(); $already=$st->get_result()->fetch_assoc(); $st->close(); if($already) throw new Exception('Эта продажа уже возвращена.');
        $st=$db->prepare("SELECT sale_id FROM `$s` WHERE sale_id=? LIMIT 1 FOR UPDATE"); $st->bind_param('i',$sale_id); $st->execute(); $sale=$st->get_result()->fetch_assoc(); $st->close(); if(!$sale) throw new Exception('Продажа не найдена.');
        $st=$db->prepare("SELECT product_id,quantity,unit_cost FROM `$si` WHERE sale_id=? ORDER BY sale_item_id"); $st->bind_param('i',$sale_id); $st->execute(); $res=$st->get_result(); $items=array(); while($r=$res->fetch_assoc())$items[]=$r; $st->close(); if(!$items) throw new Exception('В продаже нет товаров.');
        $restored=0;
        foreach($items as $it){ $pid=(int)$it['product_id']; $qty=(int)$it['quantity']; if($pid<=0||$qty<=0)continue; $st=$db->prepare("SELECT quantity FROM `$p` WHERE product_id=? FOR UPDATE"); $st->bind_param('i',$pid); $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close(); if(!$row)continue; $before=(int)$row['quantity']; $after=$before+$qty; $st=$db->prepare("UPDATE `$p` SET quantity=?,date_modified=NOW() WHERE product_id=?"); $st->bind_param('ii',$after,$pid); $st->execute(); $st->close(); $delta=$qty; $type='return'; $cost=(float)$it['unit_cost']; $note='Возврат продажи #'.$sale_id; $st=$db->prepare("INSERT INTO `$mv` (product_id,type,reference_id,quantity_delta,qty_before,qty_after,unit_cost,note,date_created) VALUES (?,?,?,?,?,?,?,?,NOW())"); $st->bind_param('isiiiids',$pid,$type,$sale_id,$delta,$before,$after,$cost,$note); $st->execute(); $st->close(); $restored+=$qty; }
        $source='stock'; $reason=trim((string)$reason); if($reason==='')$reason='Возврат'; $flag=1; $st=$db->prepare("INSERT INTO `$rt` (source,reference_id,reason,stock_restored,date_created) VALUES (?,?,?,?,NOW())"); $st->bind_param('sisi',$source,$sale_id,$reason,$flag); $st->execute(); $st->close();
        $db->commit(); return array('sale_id'=>$sale_id,'restored_items'=>$restored);
    } catch(Throwable $e){ $db->rollback(); throw $e; }
}

function x86_dashboard() {
    $db=x86db(); $p=x86p('product'); $xs=x86p('x86stock_product');
    $todayRow=$db->query("SELECT CURDATE() d")->fetch_assoc(); $todayDate=$todayRow['d'];
    $today=x86_analytics_summary($todayDate,$todayDate,'all');
    $inv=$db->query("SELECT COALESCE(SUM(p.quantity*COALESCE(xs.cost_price,0)),0) value_sum, COALESCE(SUM(CASE WHEN COALESCE(xs.cost_price,0)>0 THEN p.quantity ELSE 0 END),0) costed_units, COALESCE(SUM(CASE WHEN COALESCE(xs.cost_price,0)>0 THEN 1 ELSE 0 END),0) costed_products FROM `$p` p LEFT JOIN `$xs` xs ON xs.product_id=p.product_id WHERE p.status=1 AND p.quantity>0")->fetch_assoc();
    $procCond=x86_preorder_sql_condition('p');
    $low=$db->query("SELECT COUNT(*) c FROM `$p` p LEFT JOIN `$xs` xs ON xs.product_id=p.product_id WHERE p.status=1 AND $procCond AND p.quantity<=COALESCE(xs.min_stock,1)")->fetch_assoc();
    $stale=x86_stale(90,500); $frozen=0; foreach($stale as $r) $frozen+=$r['frozen'];
    return array('today_sales'=>(float)$today['gross_total'],'today_profit'=>(float)$today['profit_total'],'today_orders'=>(int)$today['sales_count'],'today_items'=>(int)$today['items_count'],'inventory_value'=>(float)$inv['value_sum'],'inventory_costed_units'=>(int)$inv['costed_units'],'inventory_costed_products'=>(int)$inv['costed_products'],'low_stock'=>(int)$low['c'],'stale_count'=>count($stale),'frozen_value'=>round($frozen,2));
}
function x86_inventory_categories($limit=12) {
    $db=x86db(); $p=x86p('product'); $xs=x86p('x86stock_product'); $pc=x86p('product_to_category'); $cd=x86p('category_description'); $limit=max(1,min(50,(int)$limit));
    // У товара ocStore может быть несколько категорий. Для финансового итога используем одну стабильную категорию (минимальный category_id), чтобы не считать товар дважды.
    $sql="SELECT COALESCE((SELECT name FROM `$cd` cdx WHERE cdx.category_id=pc1.category_id ORDER BY language_id LIMIT 1),'Без категории') name, COUNT(*) positions, COALESCE(SUM(p.quantity),0) units, COALESCE(SUM(p.quantity*COALESCE(xs.cost_price,0)),0) cost_value, COALESCE(SUM(p.quantity*p.price),0) retail_value FROM `$p` p LEFT JOIN `$xs` xs ON xs.product_id=p.product_id LEFT JOIN (SELECT product_id,MIN(category_id) category_id FROM `$pc` GROUP BY product_id) pc1 ON pc1.product_id=p.product_id WHERE p.status=1 AND p.quantity>0 AND COALESCE(xs.cost_price,0)>0 GROUP BY pc1.category_id ORDER BY cost_value DESC,retail_value DESC LIMIT $limit";
    $res=$db->query($sql); $out=array(); while($r=$res->fetch_assoc()){ $r['positions']=(int)$r['positions']; $r['units']=(int)$r['units']; $r['cost_value']=(float)$r['cost_value']; $r['retail_value']=(float)$r['retail_value']; $out[]=$r; } return $out;
}
function x86_recommendations($limit=50) {
    x86_ensure_returns_table(); $db=x86db(); $p=x86p('product'); $pd=x86p('product_description'); $xs=x86p('x86stock_product'); $si=x86p('x86stock_sale_item'); $s=x86p('x86stock_sale'); $op=x86p('order_product'); $o=x86p('order'); $statusSql=x86_ocstore_status_sql(); $rt=x86_returns_table(); $safety=(int)x86_setting('safety_days','10'); $leadDefault=(int)x86_setting('default_lead_days','20');
    $sql="SELECT p.product_id,p.model,p.quantity,p.price,(SELECT name FROM `$pd` d WHERE d.product_id=p.product_id ORDER BY language_id LIMIT 1) name,COALESCE(xs.cost_price,0) cost_price,COALESCE(xs.min_stock,1) min_stock,COALESCE(xs.lead_time_days,$leadDefault) lead_time_days,
      COALESCE(x.sold30,0)+COALESCE(oo.sold30,0) sold30, COALESCE(x.sold90,0)+COALESCE(oo.sold90,0) sold90
      FROM `$p` p LEFT JOIN `$xs` xs ON xs.product_id=p.product_id
      LEFT JOIN (SELECT si.product_id,SUM(CASE WHEN s.date_created>=DATE_SUB(NOW(),INTERVAL 30 DAY) THEN si.quantity ELSE 0 END) sold30,SUM(CASE WHEN s.date_created>=DATE_SUB(NOW(),INTERVAL 90 DAY) THEN si.quantity ELSE 0 END) sold90 FROM `$si` si JOIN `$s` s ON s.sale_id=si.sale_id WHERE s.date_created>=DATE_SUB(NOW(),INTERVAL 90 DAY) AND NOT EXISTS (SELECT 1 FROM `$rt` xr WHERE xr.source='stock' AND xr.reference_id=s.sale_id) GROUP BY si.product_id) x ON x.product_id=p.product_id
      LEFT JOIN (SELECT op.product_id,SUM(CASE WHEN o.date_added>=DATE_SUB(NOW(),INTERVAL 30 DAY) THEN op.quantity ELSE 0 END) sold30,SUM(CASE WHEN o.date_added>=DATE_SUB(NOW(),INTERVAL 90 DAY) THEN op.quantity ELSE 0 END) sold90 FROM `$op` op JOIN `$o` o ON o.order_id=op.order_id WHERE o.order_status_id IN ($statusSql) AND o.date_added>=DATE_SUB(NOW(),INTERVAL 90 DAY) GROUP BY op.product_id) oo ON oo.product_id=p.product_id
      WHERE p.status=1 AND ".x86_preorder_sql_condition('p')." AND COALESCE(xs.cost_price,0)>0 HAVING sold90>0 ORDER BY sold30 DESC,sold90 DESC LIMIT 500";
    $res=$db->query($sql); $out=array(); while($r=$res->fetch_assoc()){ $v30=(float)$r['sold30']/30; $v90=(float)$r['sold90']/90; $velocity=max($v30,0.7*$v30+0.3*$v90); $days=(int)$r['lead_time_days']+$safety; $target=max((int)$r['min_stock'],(int)ceil($velocity*$days)); $order=max(0,$target-(int)$r['quantity']); if($order>0) { $r['velocity']=round($velocity,3); $r['days_cover']=$velocity>0?round($r['quantity']/$velocity,1):999; $r['target_stock']=$target; $r['order_qty']=$order; $r['order_cost']=round($order*(float)$r['cost_price'],2); $out[]=$r; } }
    usort($out,function($a,$b){ return $a['days_cover']<=>$b['days_cover']; }); return array_slice($out,0,$limit);
}
function x86_stale($days=90,$limit=100) {
    x86_ensure_returns_table(); $db=x86db(); $p=x86p('product'); $pd=x86p('product_description'); $xs=x86p('x86stock_product'); $si=x86p('x86stock_sale_item'); $s=x86p('x86stock_sale'); $op=x86p('order_product'); $o=x86p('order'); $statusSql=x86_ocstore_status_sql(); $rt=x86_returns_table(); $days=max(1,(int)$days); $limit=max(1,min(500,(int)$limit));
    $sql="SELECT p.product_id,p.model,p.quantity,(SELECT name FROM `$pd` d WHERE d.product_id=p.product_id ORDER BY language_id LIMIT 1) name,COALESCE(xs.cost_price,0) cost_price,
      GREATEST(COALESCE(x.last_sale,'1970-01-01 00:00:00'),COALESCE(oo.last_sale,'1970-01-01 00:00:00')) last_sale_raw
      FROM `$p` p LEFT JOIN `$xs` xs ON xs.product_id=p.product_id
      LEFT JOIN (SELECT si.product_id,MAX(s.date_created) last_sale FROM `$si` si JOIN `$s` s ON s.sale_id=si.sale_id WHERE NOT EXISTS (SELECT 1 FROM `$rt` xr WHERE xr.source='stock' AND xr.reference_id=s.sale_id) GROUP BY si.product_id) x ON x.product_id=p.product_id
      LEFT JOIN (SELECT op.product_id,MAX(o.date_added) last_sale FROM `$op` op JOIN `$o` o ON o.order_id=op.order_id WHERE o.order_status_id IN ($statusSql) GROUP BY op.product_id) oo ON oo.product_id=p.product_id
      WHERE p.status=1 AND p.quantity>0 AND COALESCE(xs.cost_price,0)>0 HAVING last_sale_raw='1970-01-01 00:00:00' OR last_sale_raw<DATE_SUB(NOW(),INTERVAL $days DAY) ORDER BY (p.quantity*COALESCE(xs.cost_price,0)) DESC LIMIT $limit";
    $res=$db->query($sql); $out=array(); while($r=$res->fetch_assoc()){ $r['last_sale']=$r['last_sale_raw']==='1970-01-01 00:00:00'?null:$r['last_sale_raw']; unset($r['last_sale_raw']); $r['frozen']=round((int)$r['quantity']*(float)$r['cost_price'],2); $out[]=$r; } return $out;
}
function x86_top_products($days=30,$limit=20) {
    $days=max(1,(int)$days); $limit=max(1,min(100,(int)$limit));
    $to=date('Y-m-d'); $from=date('Y-m-d',strtotime('-'.($days-1).' day'));
    return x86_analytics_top_products($from,$to,$limit,'all');
}
function x86_chart30() {
    $to=date('Y-m-d'); $from=date('Y-m-d',strtotime('-29 day'));
    return x86_analytics_chart($from,$to);
}
function x86_abc($days=90) {
    $rows=x86_top_products($days,500); usort($rows,function($a,$b){return (float)$b['revenue']<=>(float)$a['revenue'];}); $total=0; foreach($rows as $r)$total+=(float)$r['revenue']; $cum=0; foreach($rows as &$r){$cum+=(float)$r['revenue'];$pct=$total>0?$cum/$total:0;$r['abc']=$pct<=0.80?'A':($pct<=0.95?'B':'C');} return $rows;
}
function x86_history($product_id,$limit=50) {
    $db=x86db(); $m=x86p('x86stock_movement'); $limit=max(1,min(200,(int)$limit)); $st=$db->prepare("SELECT * FROM `$m` WHERE product_id=? ORDER BY movement_id DESC LIMIT $limit"); $st->bind_param('i',$product_id); $st->execute(); $res=$st->get_result();$o=array();while($r=$res->fetch_assoc())$o[]=$r;$st->close();return $o;
}
function x86_openai_answer($question) {
    $key=x86_setting('openai_api_key',''); if(!$key) throw new Exception('OpenAI API key не настроен.'); if(!function_exists('curl_init')) throw new Exception('На сервере не установлен PHP cURL.');
    $model=x86_setting('openai_model','gpt-5.6-luna'); $dash=x86_dashboard(); $rec=array_slice(x86_recommendations(30),0,15); $stale=array_slice(x86_stale(90,30),0,15); $top=x86_top_products(30,15);
    $context=array('dashboard'=>$dash,'purchase_recommendations'=>$rec,'stale_stock'=>$stale,'top_profit_30d'=>$top,'tax_rate_percent'=>(float)x86_setting('tax_rate','3'),'cash_tax_enabled'=>x86_setting('tax_cash_enabled','1')==='1','commission_note'=>'Ставки payment-комиссий уже финальные, с НДС внутри комиссии; НДС к продаже отдельно не начисляется.');
    $instructions='Ты аналитик магазина X86.kz. Отвечай по-русски, кратко и предметно. Используй только переданные цифры склада и продаж. Не придумывай отсутствующие данные. Налог пользователя — налог с оборота, указанный в контексте. Если cash_tax_enabled=false, для наличных продаж налог с оборота не начисляется. Комиссии Kaspi уже передаются как финальные ставки с НДС внутри комиссии. Не предлагай автоматически менять остатки или цены: только рекомендации. Денежные суммы указывай в тенге.';
    $input="Вопрос владельца: {$question}\n\nДанные X86 Stock:\n".json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $payload=json_encode(array('model'=>$model,'instructions'=>$instructions,'input'=>$input,'max_output_tokens'=>1200),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $ch=curl_init('https://api.openai.com/v1/responses'); curl_setopt_array($ch,array(CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>array('Authorization: Bearer '.$key,'Content-Type: application/json'),CURLOPT_POSTFIELDS=>$payload,CURLOPT_TIMEOUT=>45)); $raw=curl_exec($ch); $status=curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch); if(!$raw) throw new Exception('Ошибка OpenAI: '.$err); $j=json_decode($raw,true); if($status>=300) throw new Exception('OpenAI API: '.(isset($j['error']['message'])?$j['error']['message']:'HTTP '.$status));
    if(isset($j['output_text'])&&$j['output_text']!=='') return $j['output_text'];
    $texts=array(); if(isset($j['output'])&&is_array($j['output'])) foreach($j['output'] as $o) if(isset($o['content'])&&is_array($o['content'])) foreach($o['content'] as $c) if(isset($c['text'])) $texts[]=$c['text'];
    if($texts) return implode("\n",$texts); throw new Exception('OpenAI вернул ответ без текста.');
}


// --- X86 AI product command control (v1.5.0) ---
function x86_ai_ensure_tables() {
    $db=x86db(); $t=x86p('x86stock_ai_plan');
    $sql="CREATE TABLE IF NOT EXISTS `$t` (
      plan_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      token CHAR(64) NOT NULL,
      command_text TEXT NOT NULL,
      summary TEXT NOT NULL,
      plan_json MEDIUMTEXT NOT NULL,
      status VARCHAR(16) NOT NULL DEFAULT 'pending',
      result_json MEDIUMTEXT NULL,
      created_at DATETIME NOT NULL,
      expires_at DATETIME NOT NULL,
      applied_at DATETIME NULL,
      PRIMARY KEY (plan_id),
      UNIQUE KEY token (token),
      KEY status_expires (status,expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if(!$db->query($sql)) throw new Exception('Не удалось создать таблицу AI-планов: '.$db->error);
    $db->query("UPDATE `$t` SET status='expired' WHERE status='pending' AND expires_at<NOW()");
    $db->query("DELETE FROM `$t` WHERE created_at<DATE_SUB(NOW(),INTERVAL 90 DAY)");
}
function x86_ai_openai_text($instructions,$input,$max_tokens=1200) {
    $key=x86_setting('openai_api_key',''); if(!$key) throw new Exception('OpenAI API key не настроен.');
    if(!function_exists('curl_init')) throw new Exception('На сервере не установлен PHP cURL.');
    $model=x86_setting('openai_model','gpt-5.6-luna');
    $payload=json_encode(array('model'=>$model,'instructions'=>$instructions,'input'=>$input,'max_output_tokens'=>(int)$max_tokens),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $ch=curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch,array(CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>array('Authorization: Bearer '.$key,'Content-Type: application/json'),CURLOPT_POSTFIELDS=>$payload,CURLOPT_TIMEOUT=>45));
    $raw=curl_exec($ch); $status=curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
    if(!$raw) throw new Exception('Ошибка OpenAI: '.$err);
    $j=json_decode($raw,true); if($status>=300) throw new Exception('OpenAI API: '.(isset($j['error']['message'])?$j['error']['message']:'HTTP '.$status));
    if(isset($j['output_text'])&&$j['output_text']!=='') return $j['output_text'];
    $texts=array(); if(isset($j['output'])&&is_array($j['output'])) foreach($j['output'] as $o) if(isset($o['content'])&&is_array($o['content'])) foreach($o['content'] as $c) if(isset($c['text'])) $texts[]=$c['text'];
    if($texts) return implode("\n",$texts); throw new Exception('OpenAI вернул ответ без текста.');
}
function x86_ai_parse_json_text($text) {
    $text=trim((string)$text);
    $text=preg_replace('/^```(?:json)?\s*/iu','',$text); $text=preg_replace('/\s*```$/u','',$text);
    $j=json_decode($text,true); if(is_array($j)) return $j;
    $a=strpos($text,'{'); $b=strrpos($text,'}');
    if($a!==false&&$b!==false&&$b>$a){ $j=json_decode(substr($text,$a,$b-$a+1),true); if(is_array($j)) return $j; }
    throw new Exception('AI не смог сформировать безопасный план изменений. Переформулируйте команду.');
}
function x86_ai_parse_request($command) {
    $statuses=implode(', ',array_map(function($x){return $x['name'];},x86_stock_statuses()));
    $instructions=<<<TXT
Ты управляющий складом X86.kz. Определи: владелец хочет изменить товары, найти/показать товары по условиям или задать аналитический вопрос.
Верни ТОЛЬКО валидный JSON, без markdown.

Формат:
{
  "intent":"modify_products|find_products|answer",
  "reply":"кратко как понял запрос",
  "selector":{"all_products":false,"type":"charger|keyboard|battery|cooler|matrix|mouse|service|other|null","terms_all":[],"terms_any":[],"exclude_terms":[],"filters":[{"field":"price|partner_price|cost_price|quantity|product_id|min_stock|lead_time_days|stock_status","op":"eq|gt|gte|lt|lte","value":число_или_строка}]},
  "operations":[{"field":"quantity|price|partner_price|cost_price|min_stock|lead_time_days|location|stock_status","mode":"set|add|subtract|percent_add|percent_subtract|set_percent_of","value":число_или_строка,"source_field":"price|partner_price|cost_price|null"}]
}

Правила:
- НИКОГДА не пиши SQL и не используй поля вне схемы.
- Перед массовым изменением система сама покажет список и потребует подтверждение.
- Если просят «найди/покажи/какие товары» по каталогу без изменения — intent=find_products.
- «добавь 10 штук» = quantity add 10; «поставь количество 10» = set 10; «убери/спиши 3» = subtract 3.
- «подними цену на 10%» = price percent_add 10; «снизь на 10%» = percent_subtract 10.
- «партнёрская цена 90% от розничной» = partner_price set_percent_of 90 source_field price.
- «розница 150% от себестоимости» = price set_percent_of 150 source_field cost_price.
- «без себестоимости» = filter cost_price eq 0; «без партнёрской цены» = partner_price eq 0; «нулевой остаток» = quantity eq 0.
- Статусы наличия сайта: {$statuses}. «под заказ» означает «Предзаказ», если такой статус есть. «ожидание» означает «Ожидание 2-3 дня».
- Команда «поставь/переведи в предзаказ» = stock_status set "Предзаказ". НЕ меняй количество автоматически, если владелец отдельно этого не сказал.
- «зарядка/блок питания/адаптер» = type charger; «аккумулятор/батарея/АКБ» = battery; «кулер/вентилятор» = cooler; «матрица/экран ноутбука» = matrix.
- Бренды: асер/эйсер=Acer, асус=Asus, леново=Lenovo, хп=HP, делл=Dell, самсунг=Samsung, хуавей=Huawei, сяоми=Xiaomi.
- Размеры 5.5×1.7 / 5.5x1.7 / 5,5х1,7 раскладывай в terms_all как "5.5" и "1.7".
- all_products=true только когда явно сказано «все товары».

Примеры:
«На все зарядки с ценой 10000 поставить себестоимость 5000» -> modify_products, charger, price eq 10000, cost_price set 5000.
«Зарядки Acer 3.42A 5.5×1.7 добавь 10 штук» -> modify_products, charger, terms_all Acer/3.42/5.5/1.7, quantity add 10.
«Покажи все товары без себестоимости» -> find_products, cost_price eq 0.
«Все редкие кулеры Asus переведи в предзаказ» -> modify_products, cooler + Asus, stock_status set Предзаказ.
«Все товары с нулевым остатком поставить Нет в наличии» -> modify_products, all_products=true, quantity eq 0, stock_status set Нет в наличии.
TXT;
    $out=x86_ai_openai_text($instructions,"Команда владельца: ".$command,1200);
    return x86_ai_parse_json_text($out);
}
function x86_ai_norm($s) {
    $s=html_entity_decode((string)$s,ENT_QUOTES|ENT_HTML5,'UTF-8');
    $s=mb_strtolower($s,'UTF-8'); $s=str_replace('ё','е',$s); $s=str_replace(',','.',$s);
    $s=preg_replace('/(?<=\d)[xх×\*](?=\d)/ui',' ',$s);
    $s=preg_replace('/[\(\)\[\]\{\},;:\/\\|_\-]+/u',' ',$s);
    $s=preg_replace('/\s+/u',' ',trim($s));
    $aliases=array('асер'=>'acer','эйсер'=>'acer','асус'=>'asus','леново'=>'lenovo','хп'=>'hp','делл'=>'dell','самсунг'=>'samsung','хуавей'=>'huawei','сяоми'=>'xiaomi');
    foreach($aliases as $a=>$b) $s=preg_replace('/(^|\s)'.preg_quote($a,'/').'(?=\s|$)/u','$1'.$b,$s);
    return $s;
}
function x86_ai_catalog() {
    $db=x86db(); $p=x86p('product'); $pd=x86p('product_description'); $pa=x86p('product_attribute'); $xs=x86p('x86stock_product'); $pc=x86p('product_to_category'); $cd=x86p('category_description'); $ss=x86p('stock_status'); $lid=x86_language_id(); $gid=(int)x86_setting('partner_customer_group_id','0'); $sp=x86p('product_special');
    $special="0";
    if($gid>0) $special="COALESCE((SELECT MIN(ps.price) FROM `$sp` ps WHERE ps.product_id=p.product_id AND ps.customer_group_id=$gid AND (ps.date_start IS NULL OR ps.date_start='0000-00-00' OR ps.date_start<=CURDATE()) AND (ps.date_end IS NULL OR ps.date_end='0000-00-00' OR ps.date_end>=CURDATE())),0)";
    $sql="SELECT p.product_id,p.model,p.sku,p.quantity,p.price,p.status,p.stock_status_id,
      (SELECT name FROM `$pd` d WHERE d.product_id=p.product_id ORDER BY (d.language_id=$lid) DESC,language_id ASC LIMIT 1) name,
      (SELECT name FROM `$ss` z WHERE z.stock_status_id=p.stock_status_id ORDER BY (z.language_id=$lid) DESC,language_id ASC LIMIT 1) stock_status,
      COALESCE(xs.barcode,'') barcode,COALESCE(xs.cost_price,0) cost_price,
      CASE WHEN COALESCE(xs.partner_price,0)>0 THEN xs.partner_price ELSE $special END partner_price,
      COALESCE(xs.min_stock,1) min_stock,COALESCE(xs.lead_time_days,".(int)x86_setting('default_lead_days','20').") lead_time_days,COALESCE(xs.location,'') location,
      COALESCE((SELECT GROUP_CONCAT(DISTINCT cdx.name SEPARATOR ' ') FROM `$pc` pcx JOIN `$cd` cdx ON cdx.category_id=pcx.category_id WHERE pcx.product_id=p.product_id),'') categories,
      COALESCE((SELECT CONCAT_WS(' ',d3.tag,d3.description) FROM `$pd` d3 WHERE d3.product_id=p.product_id ORDER BY (d3.language_id=$lid) DESC,d3.language_id ASC LIMIT 1),'') compatible_search,
      COALESCE((SELECT GROUP_CONCAT(DISTINCT pa3.text SEPARATOR ' ') FROM `$pa` pa3 WHERE pa3.product_id=p.product_id),'') attribute_search
      FROM `$p` p LEFT JOIN `$xs` xs ON xs.product_id=p.product_id WHERE p.status=1 ORDER BY p.product_id DESC";
    $res=$db->query($sql); if(!$res) throw new Exception('Ошибка чтения каталога: '.$db->error); $out=array();
    while($r=$res->fetch_assoc()){ if(!$r['barcode'])$r['barcode']='X86-'.$r['product_id']; $r['stock_status']=$r['stock_status']?:x86_stock_status_name($r['stock_status_id']); $out[]=$r; }
    return $out;
}
function x86_ai_type_match($type,$hay) {
    if(!$type||$type==='other') return true;
    $map=array(
      'charger'=>array('заряд','блок питан','адаптер','charger','power adapter','ac adapter'),
      'keyboard'=>array('клавиат','keyboard'),
      'battery'=>array('аккумулятор','батаре','акб','battery'),
      'cooler'=>array('кулер','вентилят','fan'),
      'matrix'=>array('матриц','диспле','lcd panel','экран ноутбук'),
      'mouse'=>array('мыш','mouse'),
      'service'=>array('ремонт','услуг','service')
    );
    if(!isset($map[$type])) return true;
    foreach($map[$type] as $x) if(mb_strpos($hay,x86_ai_norm($x),0,'UTF-8')!==false) return true;
    return false;
}
function x86_ai_filter_match($p,$f) {
    if(!is_array($f)||!isset($f['field'])||!isset($f['op'])) return true;
    $field=(string)$f['field'];
    if($field==='stock_status'){
        $a=x86_stock_status_norm(isset($p['stock_status'])?$p['stock_status']:'');
        $b=x86_stock_status_norm(isset($f['value'])?$f['value']:''); if($b==='под заказ')$b='предзаказ';
        return $f['op']==='eq' && ($a===$b || ($b!==''&&mb_strpos($a,$b,0,'UTF-8')!==false));
    }
    $allowed=array('price','partner_price','cost_price','quantity','product_id','min_stock','lead_time_days'); if(!in_array($field,$allowed,true)) return false;
    $a=(float)(isset($p[$field])?$p[$field]:0); $b=(float)(isset($f['value'])?$f['value']:0); $op=$f['op']; $eps=0.0001;
    if($op==='eq') return abs($a-$b)<$eps; if($op==='gt')return $a>$b; if($op==='gte')return $a>=$b; if($op==='lt')return $a<$b; if($op==='lte')return $a<=$b; return false;
}
function x86_ai_match_products($selector) {
    if(!is_array($selector)) $selector=array(); $all=!empty($selector['all_products']); $type=isset($selector['type'])?$selector['type']:null;
    $terms_all=isset($selector['terms_all'])&&is_array($selector['terms_all'])?$selector['terms_all']:array();
    $terms_any=isset($selector['terms_any'])&&is_array($selector['terms_any'])?$selector['terms_any']:array();
    $ex=isset($selector['exclude_terms'])&&is_array($selector['exclude_terms'])?$selector['exclude_terms']:array();
    $filters=isset($selector['filters'])&&is_array($selector['filters'])?$selector['filters']:array();
    if(!$all&&!$type&&!$terms_all&&!$terms_any&&!$filters) throw new Exception('AI не определил, какие именно товары нужно менять. Уточните товар, модель, категорию или условие.');
    $out=array(); foreach(x86_ai_catalog() as $p){
      // compatible_search включает теги и описание (в т.ч. блок «Совместимые модели»),
      // attribute_search — текстовые характеристики. Поэтому AI-команды по модели
      // находят товар даже если модель не входит в его название.
      $hay=x86_ai_norm(($p['name']?:'').' '.($p['model']?:'').' '.($p['sku']?:'').' '.($p['barcode']?:'').' '.($p['categories']?:'').' '.($p['compatible_search']?:'').' '.($p['attribute_search']?:''));
      if($type&&!x86_ai_type_match($type,$hay)) continue;
      $ok=true; foreach($terms_all as $t){$n=x86_ai_norm($t);if($n!==''&&mb_strpos($hay,$n,0,'UTF-8')===false){$ok=false;break;}} if(!$ok)continue;
      if($terms_any){$any=false;foreach($terms_any as $t){$n=x86_ai_norm($t);if($n!==''&&mb_strpos($hay,$n,0,'UTF-8')!==false){$any=true;break;}}if(!$any)continue;}
      foreach($ex as $t){$n=x86_ai_norm($t);if($n!==''&&mb_strpos($hay,$n,0,'UTF-8')!==false){$ok=false;break;}} if(!$ok)continue;
      foreach($filters as $f) if(!x86_ai_filter_match($p,$f)){$ok=false;break;} if(!$ok)continue;
      $out[]=$p;
    }
    return $out;
}
function x86_ai_field_label($f){$m=array('quantity'=>'Количество','price'=>'Розничная цена','partner_price'=>'Партнёрская цена','cost_price'=>'Себестоимость','min_stock'=>'Мин. остаток','lead_time_days'=>'Срок поставки','location'=>'Место хранения','stock_status'=>'Статус наличия');return isset($m[$f])?$m[$f]:$f;}
function x86_ai_apply_op_value($current,$op,$field,$state=array()) {
    $mode=isset($op['mode'])?$op['mode']:'set'; $value=isset($op['value'])?$op['value']:0;
    if($field==='location'){ if($mode!=='set') throw new Exception('Для места хранения доступен только set.'); return mb_substr(trim((string)$value),0,255,'UTF-8'); }
    if($field==='stock_status'){ if($mode!=='set') throw new Exception('Для статуса доступен только set.'); $ss=x86_resolve_stock_status((string)$value); return $ss['name']; }
    $c=(float)$current; $v=(float)$value;
    if($mode==='set')$n=$v; elseif($mode==='add')$n=$c+$v; elseif($mode==='subtract')$n=$c-$v; elseif($mode==='percent_add')$n=$c*(1+$v/100); elseif($mode==='percent_subtract')$n=$c*(1-$v/100);
    elseif($mode==='set_percent_of'){ $src=isset($op['source_field'])?(string)$op['source_field']:''; if(!in_array($src,array('price','partner_price','cost_price'),true))throw new Exception('Не указано корректное исходное поле для процентной цены.'); $n=(float)(isset($state[$src])?$state[$src]:0)*$v/100; }
    else throw new Exception('Неизвестная операция AI: '.$mode);
    if(in_array($field,array('quantity','min_stock','lead_time_days'),true)) $n=(int)round($n); else $n=round($n,2);
    if($n<0) throw new Exception(x86_ai_field_label($field).' не может быть меньше нуля.'); return $n;
}
function x86_ai_build_plan($command,$parsed) {
    $ops=isset($parsed['operations'])&&is_array($parsed['operations'])?$parsed['operations']:array(); if(!$ops) throw new Exception('AI не определил, что именно нужно изменить.');
    $allowed=array('quantity','price','partner_price','cost_price','min_stock','lead_time_days','location','stock_status');
    foreach($ops as $o){if(!isset($o['field'])||!in_array($o['field'],$allowed,true))throw new Exception('AI предложил неподдерживаемое изменение.');}
    $matches=x86_ai_match_products(isset($parsed['selector'])?$parsed['selector']:array());
    if(!$matches) return array('kind'=>'no_matches','answer'=>'Подходящих товаров не найдено. Ничего не изменено.');
    if(count($matches)>500) throw new Exception('Найдено '.count($matches).' товаров. Это слишком широкая команда — уточните условие.');
    $items=array();
    foreach($matches as $p){
      $before=array('quantity'=>(int)$p['quantity'],'price'=>(float)$p['price'],'partner_price'=>(float)$p['partner_price'],'cost_price'=>(float)$p['cost_price'],'min_stock'=>(int)$p['min_stock'],'lead_time_days'=>(int)$p['lead_time_days'],'location'=>(string)$p['location'],'stock_status'=>(string)$p['stock_status']);
      $after=$before;
      foreach($ops as $o){$f=$o['field'];$after[$f]=x86_ai_apply_op_value($after[$f],$o,$f,$after);}
      $changes=array(); foreach($after as $f=>$v){$b=$before[$f];$diff=is_numeric($v)&&is_numeric($b)?abs((float)$v-(float)$b)>0.0001:(string)$v!==(string)$b;if($diff)$changes[]=array('field'=>$f,'label'=>x86_ai_field_label($f),'before'=>$b,'after'=>$v);}
      if($changes)$items[]=array('product_id'=>(int)$p['product_id'],'name'=>$p['name'],'model'=>$p['model'],'barcode'=>$p['barcode'],'changes'=>$changes);
    }
    if(!$items) return array('kind'=>'no_matches','answer'=>'Товары найдены, но нужные значения уже установлены.');
    x86_ai_ensure_tables(); $db=x86db(); $t=x86p('x86stock_ai_plan'); $token=bin2hex(random_bytes(32));
    $summary=(isset($parsed['reply'])&&trim($parsed['reply'])!=='')?trim($parsed['reply']):'Подготовлены изменения товаров';
    $plan=array('command'=>$command,'selector'=>isset($parsed['selector'])?$parsed['selector']:array(),'operations'=>$ops,'items'=>$items);
    $json=json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $st=$db->prepare("INSERT INTO `$t` (token,command_text,summary,plan_json,status,created_at,expires_at) VALUES (?,?,?,?,'pending',NOW(),DATE_ADD(NOW(),INTERVAL 30 MINUTE))"); $st->bind_param('ssss',$token,$command,$summary,$json); $st->execute(); $plan_id=$db->insert_id; $st->close();
    return array('kind'=>'plan','plan'=>array('token'=>$token,'plan_id'=>$plan_id,'summary'=>$summary,'count'=>count($items),'items'=>$items,'expires_minutes'=>30));
}
function x86_ai_find_products($parsed){
    $matches=x86_ai_match_products(isset($parsed['selector'])?$parsed['selector']:array()); $items=array();
    foreach(array_slice($matches,0,200) as $p)$items[]=array('product_id'=>(int)$p['product_id'],'name'=>$p['name'],'model'=>$p['model'],'barcode'=>$p['barcode'],'quantity'=>(int)$p['quantity'],'price'=>(float)$p['price'],'cost_price'=>(float)$p['cost_price'],'partner_price'=>(float)$p['partner_price'],'stock_status'=>$p['stock_status']);
    return array('kind'=>'matches','answer'=>(isset($parsed['reply'])?$parsed['reply']:'Найдены товары'),'count'=>count($matches),'items'=>$items,'truncated'=>count($matches)>200);
}
function x86_ai_request($command) {
    $command=trim((string)$command); if($command==='') throw new Exception('Введите вопрос или команду.');
    $parsed=x86_ai_parse_request($command); $intent=isset($parsed['intent'])?$parsed['intent']:'answer';
    if($intent==='modify_products') return x86_ai_build_plan($command,$parsed);
    if($intent==='find_products') return x86_ai_find_products($parsed);
    return array('kind'=>'answer','answer'=>x86_openai_answer($command));
}
function x86_ai_value_equal($a,$b){if(is_numeric($a)&&is_numeric($b))return abs((float)$a-(float)$b)<0.0001;return (string)$a===(string)$b;}
function x86_ai_apply_plan($token,$product_ids=array()) {
    x86_ai_ensure_tables(); $db=x86db(); $t=x86p('x86stock_ai_plan'); $token=trim((string)$token); if(!$token)throw new Exception('AI-план не найден.');
    $st=$db->prepare("SELECT * FROM `$t` WHERE token=? LIMIT 1");$st->bind_param('s',$token);$st->execute();$row=$st->get_result()->fetch_assoc();$st->close(); if(!$row)throw new Exception('AI-план не найден.'); if($row['status']!=='pending')throw new Exception('Этот план уже применён, отменён или устарел.'); if(strtotime($row['expires_at'])<time())throw new Exception('План устарел. Сформируйте его заново.');
    $plan=json_decode($row['plan_json'],true);if(!is_array($plan)||empty($plan['items']))throw new Exception('Повреждённый AI-план.');
    $selected=array(); if(is_array($product_ids)&&$product_ids)foreach($product_ids as $id)$selected[(int)$id]=true; else foreach($plan['items'] as $it)$selected[(int)$it['product_id']]=true;
    if(!$selected)throw new Exception('Не выбрано ни одного товара.'); $allowedIds=array();foreach($plan['items'] as $it)$allowedIds[(int)$it['product_id']]=true;foreach($selected as $id=>$v)if(!isset($allowedIds[$id]))throw new Exception('В подтверждение попал товар вне AI-плана.');
    $pTable=x86p('product'); $mv=x86p('x86stock_movement'); $applied=array(); $db->begin_transaction();
    try{
      foreach($plan['items'] as $it){
        $pid=(int)$it['product_id'];if(!isset($selected[$pid]))continue; $cur=x86_product($pid);if(!$cur)throw new Exception('Товар #'.$pid.' больше не найден.');
        $new=array('quantity'=>(int)$cur['quantity'],'price'=>(float)$cur['price'],'partner_price'=>(float)$cur['partner_price'],'cost_price'=>(float)$cur['cost_price'],'min_stock'=>(int)$cur['min_stock'],'lead_time_days'=>(int)$cur['lead_time_days'],'location'=>(string)$cur['location'],'stock_status'=>(string)$cur['stock_status_name']);
        foreach($it['changes'] as $ch){$f=$ch['field'];if(!array_key_exists($f,$new)||!x86_ai_value_equal($new[$f],$ch['before']))throw new Exception('Товар «'.$cur['name'].'» изменился после предпросмотра. Сформируйте команду заново.');$new[$f]=$ch['after'];}
        $oldQty=(int)$cur['quantity']; $oldPrice=(float)$cur['price']; $newStatus=x86_resolve_stock_status($new['stock_status']); $sid=(int)$newStatus['stock_status_id'];
        if($new['quantity']!==$oldQty||!x86_ai_value_equal($new['price'],$oldPrice)||$sid!==(int)$cur['stock_status_id']){$st=$db->prepare("UPDATE `$pTable` SET quantity=?,price=?,stock_status_id=?,date_modified=NOW() WHERE product_id=?");$st->bind_param('idii',$new['quantity'],$new['price'],$sid,$pid);$st->execute();$st->close();}
        x86_upsert_product_meta($pid,array('cost_price'=>$new['cost_price'],'partner_price'=>$new['partner_price'],'min_stock'=>$new['min_stock'],'lead_time_days'=>$new['lead_time_days'],'location'=>$new['location'],'barcode'=>$cur['barcode']));
        if($new['quantity']!==$oldQty){$delta=$new['quantity']-$oldQty;$type='ai';$ref=(int)$row['plan_id'];$note='AI: '.mb_substr($row['command_text'],0,220,'UTF-8');$cost=(float)$new['cost_price'];$st=$db->prepare("INSERT INTO `$mv` (product_id,type,reference_id,quantity_delta,qty_before,qty_after,unit_cost,note,date_created) VALUES (?,?,?,?,?,?,?,?,NOW())");$st->bind_param('isiiiids',$pid,$type,$ref,$delta,$oldQty,$new['quantity'],$cost,$note);$st->execute();$st->close();}
        $applied[]=array('product_id'=>$pid,'name'=>$cur['name'],'changes'=>$it['changes']);
      }
      $result=json_encode(array('applied'=>$applied),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$st=$db->prepare("UPDATE `$t` SET status='applied',result_json=?,applied_at=NOW() WHERE plan_id=? AND status='pending'");$st->bind_param('si',$result,$row['plan_id']);$st->execute();if($st->affected_rows!==1)throw new Exception('AI-план уже был изменён другим запросом.');$st->close();$db->commit();
      return array('applied_count'=>count($applied),'items'=>$applied,'plan_id'=>(int)$row['plan_id']);
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function x86_ai_cancel_plan($token) {
    x86_ai_ensure_tables();$db=x86db();$t=x86p('x86stock_ai_plan');$token=trim((string)$token);$st=$db->prepare("UPDATE `$t` SET status='cancelled' WHERE token=? AND status='pending'");$st->bind_param('s',$token);$st->execute();$ok=$st->affected_rows>0;$st->close();return $ok;
}


/* ============================================================
 * X86 Stock 1.7.1 — Kaspi Smart POS bridge
 * ============================================================ */
function x86_pos_schema() {
    static $done=false; if($done) return; $done=true;
    $db=x86db(); $p=DB_PREFIX;
    $db->query("CREATE TABLE IF NOT EXISTS `{$p}x86stock_pos_bridge` (
      `bridge_id` tinyint unsigned NOT NULL DEFAULT '1',
      `token_hash` char(64) NOT NULL DEFAULT '',
      `bridge_name` varchar(100) NOT NULL DEFAULT '',
      `pos_ip` varchar(100) NOT NULL DEFAULT '',
      `terminal_id` varchar(100) NOT NULL DEFAULT '',
      `serial_num` varchar(100) NOT NULL DEFAULT '',
      `bridge_version` varchar(30) NOT NULL DEFAULT '',
      `state` varchar(30) NOT NULL DEFAULT 'offline',
      `last_error` varchar(255) NOT NULL DEFAULT '',
      `info_json` mediumtext NULL,
      `created_at` datetime NULL,
      `last_seen` datetime NULL,
      PRIMARY KEY (`bridge_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("CREATE TABLE IF NOT EXISTS `{$p}x86stock_pos_job` (
      `job_id` bigint unsigned NOT NULL AUTO_INCREMENT,
      `type` varchar(30) NOT NULL DEFAULT 'payment',
      `status` varchar(30) NOT NULL DEFAULT 'pending',
      `amount` int unsigned NOT NULL DEFAULT '0',
      `payload_json` mediumtext NULL,
      `result_json` mediumtext NULL,
      `process_id` varchar(100) NOT NULL DEFAULT '',
      `payment_code` varchar(60) NOT NULL DEFAULT '',
      `sale_id` int unsigned NOT NULL DEFAULT '0',
      `message` varchar(255) NOT NULL DEFAULT '',
      `created_at` datetime NOT NULL,
      `picked_at` datetime NULL,
      `updated_at` datetime NULL,
      `completed_at` datetime NULL,
      `expires_at` datetime NULL,
      PRIMARY KEY (`job_id`),
      KEY `status_created` (`status`,`created_at`),
      KEY `sale_id` (`sale_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function x86_pos_pairing_create() {
    x86_pos_schema(); $db=x86db(); $t=x86p('x86stock_pos_bridge');
    $token='x86pos_'.bin2hex(random_bytes(24)); $hash=hash('sha256',$token);
    $st=$db->prepare("INSERT INTO `$t` (bridge_id,token_hash,state,created_at,last_seen) VALUES (1,?,'offline',NOW(),NULL) ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash),state='offline',last_error='',created_at=NOW(),last_seen=NULL");
    $st->bind_param('s',$hash); $st->execute(); $st->close(); return $token;
}
function x86_pos_bridge_row() {
    x86_pos_schema(); $db=x86db(); $t=x86p('x86stock_pos_bridge'); $q=$db->query("SELECT * FROM `$t` WHERE bridge_id=1 LIMIT 1"); return $q?$q->fetch_assoc():null;
}
function x86_pos_bridge_status() {
    $r=x86_pos_bridge_row(); if(!$r) return array('paired'=>false,'online'=>false,'state'=>'offline');
    $last=$r['last_seen']?strtotime($r['last_seen']):0; $online=$last && (time()-$last)<=25;
    return array('paired'=>$r['token_hash']!=='','online'=>$online,'state'=>$online?($r['state']?:'online'):'offline','bridge_name'=>$r['bridge_name'],'pos_ip'=>$r['pos_ip'],'terminal_id'=>$r['terminal_id'],'serial_num'=>$r['serial_num'],'bridge_version'=>$r['bridge_version'],'last_error'=>$r['last_error'],'last_seen'=>$r['last_seen']);
}
function x86_pos_bridge_auth_token($token) {
    $r=x86_pos_bridge_row(); if(!$r || !$r['token_hash'] || !$token) return false; return hash_equals($r['token_hash'],hash('sha256',(string)$token));
}
function x86_pos_bridge_heartbeat($body) {
    x86_pos_schema(); $db=x86db(); $t=x86p('x86stock_pos_bridge');
    $name=substr(trim(isset($body['bridge_name'])?$body['bridge_name']:''),0,100); $ip=substr(trim(isset($body['pos_ip'])?$body['pos_ip']:''),0,100);
    $terminal=substr(trim(isset($body['terminal_id'])?$body['terminal_id']:''),0,100); $serial=substr(trim(isset($body['serial_num'])?$body['serial_num']:''),0,100);
    $ver=substr(trim(isset($body['bridge_version'])?$body['bridge_version']:''),0,30); $state=substr(trim(isset($body['state'])?$body['state']:'online'),0,30);
    $err=substr(trim(isset($body['last_error'])?$body['last_error']:''),0,255); $info=json_encode(isset($body['info'])?$body['info']:array(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $st=$db->prepare("UPDATE `$t` SET bridge_name=?,pos_ip=?,terminal_id=?,serial_num=?,bridge_version=?,state=?,last_error=?,info_json=?,last_seen=NOW() WHERE bridge_id=1");
    $st->bind_param('ssssssss',$name,$ip,$terminal,$serial,$ver,$state,$err,$info); $st->execute(); $st->close();
    return x86_pos_bridge_status();
}
function x86_pos_calc_cart($items,$customer_type='retail') {
    if(!is_array($items)||!count($items)) throw new Exception('Корзина пуста.'); $out=array(); $gross=0.0;
    foreach($items as $it){ $pid=(int)(isset($it['product_id'])?$it['product_id']:0); $qty=max(1,(int)(isset($it['quantity'])?$it['quantity']:1)); $p=x86_product($pid); if(!$p) throw new Exception('Товар #'.$pid.' не найден.');
        if((int)$p['quantity']<$qty) throw new Exception($p['name'].': недостаточно товара. Остаток '.(int)$p['quantity']);
        $default=$customer_type==='partner'?(float)$p['partner_price']:(float)$p['price']; $price=isset($it['unit_price'])?(float)$it['unit_price']:$default; if($price<0) throw new Exception('Цена не может быть отрицательной.');
        $gross+=$price*$qty; $out[]=array('product_id'=>$pid,'quantity'=>$qty,'unit_price'=>$price,'name'=>$p['name'],'model'=>$p['model']);
    }
    $rounded=(int)round($gross); if(abs($gross-$rounded)>0.001) throw new Exception('Smart POS принимает сумму в целых тенге. Итог продажи должен быть без тиын.'); if($rounded<=0) throw new Exception('Сумма оплаты должна быть больше 0.');
    return array('items'=>$out,'gross'=>$gross,'amount'=>$rounded);
}
function x86_pos_create_payment_job($items,$customer_type='retail',$note='') {
    x86_pos_schema(); $bs=x86_pos_bridge_status(); if(empty($bs['online'])) throw new Exception('X86 POS Bridge не подключён. Запустите Bridge на компьютере в одной сети со Smart POS.');
    $cart=x86_pos_calc_cart($items,$customer_type); $payload=array('items'=>$cart['items'],'customer_type'=>$customer_type==='partner'?'partner':'retail','note'=>substr((string)$note,0,200));
    $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); $db=x86db(); $t=x86p('x86stock_pos_job'); $status='pending'; $type='payment'; $msg='Ожидание X86 POS Bridge'; $amount=$cart['amount'];
    $st=$db->prepare("INSERT INTO `$t` (type,status,amount,payload_json,message,created_at,updated_at,expires_at) VALUES (?,?,?,?,?,NOW(),NOW(),DATE_ADD(NOW(),INTERVAL 10 MINUTE))");
    $st->bind_param('ssiss',$type,$status,$amount,$json,$msg); $st->execute(); $id=$db->insert_id; $st->close(); return x86_pos_job($id);
}
function x86_pos_job($job_id) {
    x86_pos_schema(); $db=x86db(); $t=x86p('x86stock_pos_job'); $id=(int)$job_id; $st=$db->prepare("SELECT * FROM `$t` WHERE job_id=? LIMIT 1"); $st->bind_param('i',$id); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close(); if(!$r) return null;
    $r['job_id']=(int)$r['job_id']; $r['amount']=(int)$r['amount']; $r['sale_id']=(int)$r['sale_id']; unset($r['payload_json']); $r['result']=$r['result_json']?json_decode($r['result_json'],true):null; unset($r['result_json']); return $r;
}
function x86_pos_bridge_poll() {
    x86_pos_schema(); $db=x86db(); $t=x86p('x86stock_pos_job'); $db->begin_transaction(); try {
        $q=$db->query("SELECT * FROM `$t` WHERE status='pending' AND (expires_at IS NULL OR expires_at>NOW()) ORDER BY job_id ASC LIMIT 1 FOR UPDATE"); $r=$q?$q->fetch_assoc():null;
        if(!$r){$db->commit();return null;} $id=(int)$r['job_id']; $st=$db->prepare("UPDATE `$t` SET status='processing',message='Передано в Smart POS',picked_at=NOW(),updated_at=NOW() WHERE job_id=?"); $st->bind_param('i',$id); $st->execute(); $st->close(); $db->commit();
        return array('job_id'=>$id,'type'=>$r['type'],'amount'=>(int)$r['amount'],'payload'=>$r['payload_json']?json_decode($r['payload_json'],true):array());
    } catch(Throwable $e){$db->rollback();throw $e;}
}
function x86_pos_payment_code_from_result($result) {
    $d=is_array($result)&&isset($result['data'])&&is_array($result['data'])?$result['data']:array(); $a=isset($d['addInfo'])&&is_array($d['addInfo'])?$d['addInfo']:array();
    $type=mb_strtolower(trim(isset($a['ProductType'])?$a['ProductType']:''),'UTF-8'); $term=(int)(isset($a['LoanTerm'])?$a['LoanTerm']:0); $offer=!empty($a['IsOffer']);
    if(strpos($type,'red')!==false) return 'kaspi_red';
    if(strpos($type,'credit')!==false){ if($offer&&$term>=20)return 'kaspi_qr_24'; if($offer&&$term>=10)return 'kaspi_qr_12'; return 'kaspi_kredit'; }
    if(strpos($type,'bankintegration')!==false) return 'other_bank_card';
    if(strpos($type,'business')!==false) return 'kaspi_gold';
    return 'kaspi_gold';
}
function x86_pos_finalize_payment_job($job_id) {
    x86_pos_schema(); $db=x86db(); $t=x86p('x86stock_pos_job'); $id=(int)$job_id;
    $st=$db->prepare("SELECT * FROM `$t` WHERE job_id=? LIMIT 1"); $st->bind_param('i',$id); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close(); if(!$r) throw new Exception('POS операция не найдена.'); if((int)$r['sale_id']>0)return x86_pos_job($id);
    $result=$r['result_json']?json_decode($r['result_json'],true):array(); $data=isset($result['data'])&&is_array($result['data'])?$result['data']:array(); if((isset($data['status'])?$data['status']:'')!=='success') throw new Exception('Оплата Smart POS ещё не подтверждена.');
    $payload=$r['payload_json']?json_decode($r['payload_json'],true):array(); $code=x86_pos_payment_code_from_result($result); if(!x86_commission_by_code($code)) $code='kaspi_gold';
    $tx=isset($data['transactionId'])?$data['transactionId']:''; $method=isset($data['chequeInfo']['method'])?$data['chequeInfo']['method']:''; $note=trim((isset($payload['note'])?$payload['note'].'; ':'').'Kaspi Smart POS; job #'.$id.($tx?'; transaction '.$tx:'').($method?'; method '.$method:''));
    try { $sale=x86_make_sale(isset($payload['items'])?$payload['items']:array(),$code,isset($payload['customer_type'])?$payload['customer_type']:'retail',$note); }
    catch(Throwable $e){ $msg=substr('ОПЛАТА ПРОШЛА, но склад не списан: '.$e->getMessage(),0,255); $st=$db->prepare("UPDATE `$t` SET status='paid_unposted',message=?,payment_code=?,updated_at=NOW(),completed_at=NOW() WHERE job_id=?");$st->bind_param('ssi',$msg,$code,$id);$st->execute();$st->close(); return x86_pos_job($id); }
    $sid=(int)$sale['sale_id']; $msg='Оплата успешна. Продажа #'.$sid; $st=$db->prepare("UPDATE `$t` SET status='success',sale_id=?,payment_code=?,message=?,updated_at=NOW(),completed_at=NOW() WHERE job_id=?"); $st->bind_param('issi',$sid,$code,$msg,$id); $st->execute(); $st->close(); return x86_pos_job($id);
}
function x86_pos_bridge_update_job($body) {
    x86_pos_schema(); $id=(int)(isset($body['job_id'])?$body['job_id']:0); if(!$id) throw new Exception('job_id required'); $db=x86db(); $t=x86p('x86stock_pos_job');
    $status=substr(trim(isset($body['status'])?$body['status']:'processing'),0,30); $process=substr(trim(isset($body['process_id'])?$body['process_id']:''),0,100); $msg=substr(trim(isset($body['message'])?$body['message']:''),0,255); $result=isset($body['result'])?$body['result']:null; $json=$result!==null?json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null;
    $allowed=array('processing','success','fail','unknown'); if(!in_array($status,$allowed,true))$status='processing';
    if($json!==null){$st=$db->prepare("UPDATE `$t` SET status=?,process_id=?,message=?,result_json=?,updated_at=NOW(),completed_at=IF(? IN ('success','fail','unknown'),NOW(),completed_at) WHERE job_id=?");$st->bind_param('sssssi',$status,$process,$msg,$json,$status,$id);}else{$st=$db->prepare("UPDATE `$t` SET status=?,process_id=?,message=?,updated_at=NOW() WHERE job_id=?");$st->bind_param('sssi',$status,$process,$msg,$id);} $st->execute();$st->close();
    if($status==='success') return x86_pos_finalize_payment_job($id); return x86_pos_job($id);
}
function x86_pos_retry_finalize($job_id){return x86_pos_finalize_payment_job((int)$job_id);}


// --- X86 unified sales analytics (v1.8.0) ---
function x86_analytics_date($value,$fallback) {
    $value=trim((string)$value); if($value==='') return $fallback;
    $d=DateTime::createFromFormat('Y-m-d',$value);
    return ($d && $d->format('Y-m-d')===$value) ? $value : $fallback;
}
function x86_analytics_range($from='',$to='') {
    $today=date('Y-m-d'); $to=x86_analytics_date($to,$today); $from=x86_analytics_date($from,date('Y-m-d',strtotime($to.' -29 day')));
    if($from>$to){$x=$from;$from=$to;$to=$x;} return array($from,$to);
}
function x86_analytics_norm($s){
    $s=html_entity_decode((string)$s,ENT_QUOTES|ENT_HTML5,'UTF-8'); $s=mb_strtolower(trim($s),'UTF-8');
    $s=str_replace(array('ё','–','—','_','-'),array('е',' ',' ',' ',' '),$s); return preg_replace('/\s+/u',' ',$s);
}
function x86_site_payment_table(){ return x86p('x86stock_site_payment'); }
function x86_ensure_site_payment_table(){
    static $done=false; if($done)return; $done=true; $db=x86db(); $t=x86_site_payment_table();
    $db->query("CREATE TABLE IF NOT EXISTS `$t` (
      `order_id` int(11) NOT NULL,
      `payment_code` varchar(60) NOT NULL DEFAULT '',
      `date_modified` datetime NOT NULL,
      PRIMARY KEY (`order_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function x86_site_payment_options(){ return x86_payment_options_compact(); }
function x86_site_payment_set($order_id,$payment_code){
    x86_ensure_site_payment_table(); $order_id=(int)$order_id; $payment_code=trim((string)$payment_code); if($order_id<=0)throw new Exception('Неверный номер заказа.');
    $db=x86db(); $o=x86p('order'); $st=$db->prepare("SELECT order_id FROM `$o` WHERE order_id=? LIMIT 1");$st->bind_param('i',$order_id);$st->execute();$exists=$st->get_result()->fetch_assoc();$st->close();if(!$exists)throw new Exception('Заказ сайта не найден.');
    $t=x86_site_payment_table();
    if($payment_code===''){ $st=$db->prepare("DELETE FROM `$t` WHERE order_id=?");$st->bind_param('i',$order_id);$st->execute();$st->close(); return array('order_id'=>$order_id,'payment_code'=>'','payment_name'=>'Не выбрана','rate'=>0.0,'manual'=>false); }
    $comm=x86_commission_by_code($payment_code); if(!$comm)throw new Exception('Выбранный способ оплаты недоступен.');
    $st=$db->prepare("INSERT INTO `$t` (order_id,payment_code,date_modified) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE payment_code=VALUES(payment_code),date_modified=NOW()");$st->bind_param('is',$order_id,$payment_code);$st->execute();$st->close();
    return array('order_id'=>$order_id,'payment_code'=>$comm['code'],'payment_name'=>$comm['name'],'rate'=>(float)$comm['rate'],'manual'=>true);
}
function x86_stock_payment_set($sale_id,$payment_code){
    $sale_id=(int)$sale_id; $payment_code=trim((string)$payment_code); if($sale_id<=0)throw new Exception('Неверный номер продажи.'); if($payment_code==='')throw new Exception('Для продажи со склада выберите способ оплаты.');
    $comm=x86_commission_by_code($payment_code); if(!$comm)throw new Exception('Выбранный способ оплаты недоступен.');
    $db=x86db(); $s=x86p('x86stock_sale'); $si=x86p('x86stock_sale_item'); $db->begin_transaction();
    try{
        $st=$db->prepare("SELECT sale_id,gross_total,tax_total,cost_total FROM `$s` WHERE sale_id=? LIMIT 1 FOR UPDATE");$st->bind_param('i',$sale_id);$st->execute();$sale=$st->get_result()->fetch_assoc();$st->close();if(!$sale)throw new Exception('Продажа не найдена.');
        $rate=(float)$comm['rate']; $name=(string)$comm['name'];$code=(string)$comm['code'];$taxRate=x86_tax_rate_for_payment($code,$name);$commissionTotal=0.0;$taxTotal=0.0;$profitTotal=0.0;
        $st=$db->prepare("SELECT sale_item_id,quantity,unit_cost,line_total FROM `$si` WHERE sale_id=? ORDER BY sale_item_id FOR UPDATE");$st->bind_param('i',$sale_id);$st->execute();$res=$st->get_result();$items=array();while($r=$res->fetch_assoc())$items[]=$r;$st->close();if(!$items)throw new Exception('В продаже нет товаров.');
        foreach($items as $it){$itemId=(int)$it['sale_item_id'];$line=(float)$it['line_total'];$cost=(float)$it['unit_cost']*(int)$it['quantity'];$commission=round($line*$rate/100,2);$tax=round($line*$taxRate/100,2);$profit=round($line-$commission-$tax-$cost,2);$commissionTotal+=$commission;$taxTotal+=$tax;$profitTotal+=$profit;$st=$db->prepare("UPDATE `$si` SET commission_amount=?,tax_amount=?,profit_amount=? WHERE sale_item_id=?");$st->bind_param('dddi',$commission,$tax,$profit,$itemId);$st->execute();$st->close();}
        $commissionTotal=round($commissionTotal,2);$taxTotal=round($taxTotal,2);$profitTotal=round($profitTotal,2);
        $st=$db->prepare("UPDATE `$s` SET payment_code=?,payment_name=?,commission_rate=?,tax_rate=?,commission_total=?,tax_total=?,profit_total=? WHERE sale_id=?");$st->bind_param('ssdddddi',$code,$name,$rate,$taxRate,$commissionTotal,$taxTotal,$profitTotal,$sale_id);$st->execute();$st->close();
        $db->commit(); return array('source'=>'stock','sale_id'=>$sale_id,'payment_code'=>$code,'payment_name'=>$name,'rate'=>$rate,'commission_total'=>$commissionTotal,'tax_total'=>$taxTotal,'profit_total'=>$profitTotal);
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function x86_ocstore_commission_match($payment_code='',$payment_method='') {
    $hay=x86_analytics_norm($payment_code.' '.$payment_method); $best=null; $matchedCode='';
    foreach(x86_effective_commissions() as $c){
        $name=x86_analytics_norm($c['name']); $hit=false;
        foreach(x86_commission_codes($c['code']) as $alias){
            $code=x86_analytics_norm($alias);
            if($hay!=='' && $code!=='' && (strpos($hay,$code)!==false || strpos($code,$hay)!==false)){ $hit=true; $matchedCode=$alias; break; }
            foreach(array('red','gold','жума','рассрочка','налич','cash','kaspi','kredit') as $token){
                if(strpos($hay,$token)!==false && strpos($code,$token)!==false){$hit=true;$matchedCode=$alias;break 2;}
            }
        }
        if(!$hit && $hay!=='' && $name!=='' && strpos($hay,$name)!==false){$hit=true;$aliases=x86_commission_codes($c['code']);$matchedCode=$aliases?$aliases[0]:'';}
        if($hit){$best=$c;break;}
    }
    if(!$best) return array('code'=>'','name'=>'Не выбрана','rate'=>0.0,'matched'=>false,'raw_code'=>(string)$payment_code,'raw_method'=>(string)$payment_method);
    return array('code'=>$matchedCode,'name'=>$best['name'],'rate'=>(float)$best['rate'],'matched'=>true,'raw_code'=>(string)$payment_code,'raw_method'=>(string)$payment_method);
}
function x86_ocstore_sales_rows($from,$to,$limit=1000) {
    list($from,$to)=x86_analytics_range($from,$to); $limit=max(1,min(5000,(int)$limit)); x86_ensure_site_payment_table();
    $db=x86db(); $o=x86p('order'); $oh=x86p('order_history'); $op=x86p('order_product'); $xs=x86p('x86stock_product'); $ov=x86_site_payment_table(); $statusSql=x86_ocstore_status_sql();
    $sql="SELECT o.order_id,o.firstname,o.lastname,o.telephone,o.total,o.payment_method,o.payment_code,o.order_status_id,o.date_added,o.date_modified,ov.payment_code x86_payment_code,
      COALESCE(sd.sale_date,o.date_added) sale_date,COALESCE(c.items_count,0) items_count,COALESCE(c.cost_total,0) cost_total
      FROM `$o` o
      LEFT JOIN `$ov` ov ON ov.order_id=o.order_id
      LEFT JOIN (SELECT order_id,MIN(date_added) sale_date FROM `$oh` WHERE order_status_id IN ($statusSql) GROUP BY order_id) sd ON sd.order_id=o.order_id
      LEFT JOIN (SELECT op.order_id,SUM(op.quantity) items_count,SUM(op.quantity*COALESCE(xs.cost_price,0)) cost_total FROM `$op` op LEFT JOIN `$xs` xs ON xs.product_id=op.product_id GROUP BY op.order_id) c ON c.order_id=o.order_id
      WHERE o.order_status_id IN ($statusSql) AND COALESCE(sd.sale_date,o.date_added)>=? AND COALESCE(sd.sale_date,o.date_added)<DATE_ADD(?,INTERVAL 1 DAY)
      ORDER BY sale_date DESC,o.order_id DESC LIMIT $limit";
    $st=$db->prepare($sql); $st->bind_param('ss',$from,$to); $st->execute(); $res=$st->get_result(); $out=array();
    while($r=$res->fetch_assoc()){
        $manual=trim((string)$r['x86_payment_code'])!==''; $comm=$manual?x86_ocstore_commission_match($r['x86_payment_code'],''):x86_ocstore_commission_match($r['payment_code'],$r['payment_method']); $gross=(float)$r['total']; $cost=(float)$r['cost_total'];
        $commission=round($gross*(float)$comm['rate']/100,2); $taxRate=x86_tax_rate_for_payment($comm['code'],$comm['name']); $tax=round($gross*$taxRate/100,2); $profit=round($gross-$commission-$tax-$cost,2);
        $r['gross_total']=$gross; $r['commission_total']=$commission; $r['tax_total']=$tax; $r['cost_total']=$cost; $r['profit_total']=$profit; $r['commission_rate']=(float)$comm['rate']; $r['payment_display']=$comm['name']; $r['payment_code_effective']=$comm['code']; $r['payment_manual']=$manual; $r['payment_matched']=!empty($comm['matched']); $r['items_count']=(int)$r['items_count'];
        $out[]=$r;
    } $st->close(); return $out;
}
function x86_analytics_stock_summary($from,$to) {
    x86_ensure_returns_table(); list($from,$to)=x86_analytics_range($from,$to); $db=x86db(); $s=x86p('x86stock_sale'); $si=x86p('x86stock_sale_item'); $rt=x86_returns_table();
    $sql="SELECT COALESCE(SUM(gross_total),0) gross_total,COALESCE(SUM(commission_total),0) commission_total,COALESCE(SUM(tax_total),0) tax_total,COALESCE(SUM(cost_total),0) cost_total,COALESCE(SUM(profit_total),0) profit_total,COUNT(*) sales_count FROM `$s` s0 WHERE s0.date_created>=? AND s0.date_created<DATE_ADD(?,INTERVAL 1 DAY) AND NOT EXISTS (SELECT 1 FROM `$rt` xr WHERE xr.source='stock' AND xr.reference_id=s0.sale_id)";
    $st=$db->prepare($sql); $st->bind_param('ss',$from,$to); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close();
    $sql2="SELECT COALESCE(SUM(si.quantity),0) items_count FROM `$si` si JOIN `$s` s ON s.sale_id=si.sale_id WHERE s.date_created>=? AND s.date_created<DATE_ADD(?,INTERVAL 1 DAY) AND NOT EXISTS (SELECT 1 FROM `$rt` xr WHERE xr.source='stock' AND xr.reference_id=s.sale_id)";
    $st=$db->prepare($sql2); $st->bind_param('ss',$from,$to); $st->execute(); $i=$st->get_result()->fetch_assoc(); $st->close(); $r['items_count']=(int)$i['items_count'];
    foreach(array('gross_total','commission_total','tax_total','cost_total','profit_total') as $k)$r[$k]=(float)$r[$k]; $r['sales_count']=(int)$r['sales_count']; return $r;
}
function x86_analytics_site_summary($from,$to) {
    $rows=x86_ocstore_sales_rows($from,$to,5000); $o=array('gross_total'=>0.0,'commission_total'=>0.0,'tax_total'=>0.0,'cost_total'=>0.0,'profit_total'=>0.0,'sales_count'=>0,'items_count'=>0);
    foreach($rows as $r){foreach(array('gross_total','commission_total','tax_total','cost_total','profit_total') as $k)$o[$k]+=(float)$r[$k];$o['sales_count']++;$o['items_count']+=(int)$r['items_count'];}
    foreach(array('gross_total','commission_total','tax_total','cost_total','profit_total') as $k)$o[$k]=round($o[$k],2); return $o;
}
function x86_analytics_summary($from='',$to='',$source='all') {
    list($from,$to)=x86_analytics_range($from,$to); $source=in_array($source,array('all','stock','site'),true)?$source:'all';
    $stock=$source==='site'?array('gross_total'=>0,'commission_total'=>0,'tax_total'=>0,'cost_total'=>0,'profit_total'=>0,'sales_count'=>0,'items_count'=>0):x86_analytics_stock_summary($from,$to);
    $site=$source==='stock'?array('gross_total'=>0,'commission_total'=>0,'tax_total'=>0,'cost_total'=>0,'profit_total'=>0,'sales_count'=>0,'items_count'=>0):x86_analytics_site_summary($from,$to);
    $out=array('from'=>$from,'to'=>$to,'source'=>$source,'gross_total'=>0.0,'commission_total'=>0.0,'tax_total'=>0.0,'cost_total'=>0.0,'profit_total'=>0.0,'sales_count'=>0,'items_count'=>0,'stock_sales'=>(int)$stock['sales_count'],'site_orders'=>(int)$site['sales_count']);
    foreach(array('gross_total','commission_total','tax_total','cost_total','profit_total') as $k)$out[$k]=round((float)$stock[$k]+(float)$site[$k],2);
    $out['sales_count']=(int)$stock['sales_count']+(int)$site['sales_count']; $out['items_count']=(int)$stock['items_count']+(int)$site['items_count']; return $out;
}
function x86_analytics_history($from='',$to='',$source='all',$limit=100) {
    x86_ensure_returns_table(); list($from,$to)=x86_analytics_range($from,$to); $source=in_array($source,array('all','stock','site'),true)?$source:'all'; $limit=max(1,min(300,(int)$limit)); $out=array(); $db=x86db();
    if($source!=='site'){
        $s=x86p('x86stock_sale'); $si=x86p('x86stock_sale_item'); $rt=x86_returns_table();
        $sql="SELECT s.sale_id id,s.date_created sale_date,s.gross_total,s.commission_total,s.tax_total,s.cost_total,s.profit_total,s.payment_name payment_display,s.payment_code,s.customer_type,s.note,COALESCE(SUM(si.quantity),0) items_count FROM `$s` s LEFT JOIN `$si` si ON si.sale_id=s.sale_id WHERE s.date_created>=? AND s.date_created<DATE_ADD(?,INTERVAL 1 DAY) AND NOT EXISTS (SELECT 1 FROM `$rt` xr WHERE xr.source='stock' AND xr.reference_id=s.sale_id) GROUP BY s.sale_id ORDER BY s.date_created DESC LIMIT $limit";
        $st=$db->prepare($sql); $st->bind_param('ss',$from,$to); $st->execute(); $res=$st->get_result(); while($r=$res->fetch_assoc()){ $r['source']='stock';$r['source_label']='X86 Склад';$r['id']=(int)$r['id'];$r['items_count']=(int)$r['items_count']; foreach(array('gross_total','commission_total','tax_total','cost_total','profit_total') as $k)$r[$k]=(float)$r[$k];$out[]=$r;} $st->close();
    }
    if($source!=='stock'){
        foreach(x86_ocstore_sales_rows($from,$to,$limit) as $r){$out[]=array('source'=>'site','source_label'=>'Сайт','id'=>(int)$r['order_id'],'sale_date'=>$r['sale_date'],'gross_total'=>(float)$r['gross_total'],'commission_total'=>(float)$r['commission_total'],'tax_total'=>(float)$r['tax_total'],'cost_total'=>(float)$r['cost_total'],'profit_total'=>(float)$r['profit_total'],'payment_display'=>$r['payment_display'],'payment_code'=>$r['payment_code_effective'],'payment_manual'=>!empty($r['payment_manual']),'payment_matched'=>!empty($r['payment_matched']),'items_count'=>(int)$r['items_count'],'customer_type'=>'site','customer'=>trim($r['firstname'].' '.$r['lastname']),'note'=>'Заказ сайта #'.$r['order_id']);}
    }
    usort($out,function($a,$b){$c=strcmp($b['sale_date'],$a['sale_date']);if($c!==0)return $c;return (int)$b['id']<=>(int)$a['id'];}); return array_slice($out,0,$limit);
}
function x86_analytics_sale_detail($source,$id) {
    $source=$source==='site'?'site':'stock'; $id=(int)$id; if($id<=0) throw new Exception('Неверный номер продажи.'); $db=x86db();
    if($source==='stock'){
        $s=x86p('x86stock_sale'); $si=x86p('x86stock_sale_item'); $st=$db->prepare("SELECT * FROM `$s` WHERE sale_id=? LIMIT 1");$st->bind_param('i',$id);$st->execute();$sale=$st->get_result()->fetch_assoc();$st->close();if(!$sale)throw new Exception('Продажа не найдена.');
        $st=$db->prepare("SELECT product_id,name,model,quantity,unit_price,unit_cost,line_total,commission_amount,tax_amount,profit_amount FROM `$si` WHERE sale_id=? ORDER BY sale_item_id");$st->bind_param('i',$id);$st->execute();$res=$st->get_result();$items=array();while($r=$res->fetch_assoc())$items[]=$r;$st->close();
        return array('source'=>'stock','id'=>$id,'sale_date'=>$sale['date_created'],'payment_display'=>$sale['payment_name'],'payment_code'=>$sale['payment_code'],'payment_options'=>x86_site_payment_options(),'gross_total'=>(float)$sale['gross_total'],'commission_total'=>(float)$sale['commission_total'],'tax_total'=>(float)$sale['tax_total'],'cost_total'=>(float)$sale['cost_total'],'profit_total'=>(float)$sale['profit_total'],'note'=>$sale['note'],'items'=>$items,'cost_note'=>'Себестоимость сохранена на момент продажи. Способ оплаты можно изменить — комиссия и прибыль пересчитаются сразу.');
    }
    x86_ensure_site_payment_table(); $o=x86p('order');$op=x86p('order_product');$xs=x86p('x86stock_product');$oh=x86p('order_history');$ov=x86_site_payment_table();$statusSql=x86_ocstore_status_sql();
    $sql="SELECT o.*,ov.payment_code x86_payment_code,COALESCE(sd.sale_date,o.date_added) sale_date FROM `$o` o LEFT JOIN `$ov` ov ON ov.order_id=o.order_id LEFT JOIN (SELECT order_id,MIN(date_added) sale_date FROM `$oh` WHERE order_status_id IN ($statusSql) GROUP BY order_id) sd ON sd.order_id=o.order_id WHERE o.order_id=? AND o.order_status_id IN ($statusSql) LIMIT 1";
    $st=$db->prepare($sql);$st->bind_param('i',$id);$st->execute();$sale=$st->get_result()->fetch_assoc();$st->close();if(!$sale)throw new Exception('Завершённый заказ сайта не найден.');
    $manual=trim((string)$sale['x86_payment_code'])!==''; $comm=$manual?x86_ocstore_commission_match($sale['x86_payment_code'],''):x86_ocstore_commission_match($sale['payment_code'],$sale['payment_method']); $taxRate=x86_tax_rate_for_payment($comm['code'],$comm['name']); $st=$db->prepare("SELECT op.product_id,op.name,op.model,op.quantity,op.price,op.total,COALESCE(xs.cost_price,0) unit_cost FROM `$op` op LEFT JOIN `$xs` xs ON xs.product_id=op.product_id WHERE op.order_id=? ORDER BY op.order_product_id");$st->bind_param('i',$id);$st->execute();$res=$st->get_result();$items=array();$cost=0;
    while($r=$res->fetch_assoc()){ $line=(float)$r['total'];$qty=(int)$r['quantity'];$unitCost=(float)$r['unit_cost'];$lc=$unitCost*$qty;$cost+=$lc;$r['unit_price']=(float)$r['price'];$r['line_total']=$line;$r['commission_amount']=round($line*(float)$comm['rate']/100,2);$r['tax_amount']=round($line*$taxRate/100,2);$r['profit_amount']=round($line-$r['commission_amount']-$r['tax_amount']-$lc,2);$items[]=$r;} $st->close();
    $gross=(float)$sale['total'];$commission=round($gross*(float)$comm['rate']/100,2);$tax=round($gross*$taxRate/100,2);$profit=round($gross-$commission-$tax-$cost,2);
    return array('source'=>'site','id'=>$id,'sale_date'=>$sale['sale_date'],'payment_display'=>$comm['name'],'payment_code'=>$comm['code'],'payment_manual'=>$manual,'payment_matched'=>!empty($comm['matched']),'payment_options'=>x86_site_payment_options(),'gross_total'=>$gross,'commission_total'=>$commission,'tax_total'=>$tax,'cost_total'=>round($cost,2),'profit_total'=>$profit,'customer'=>trim($sale['firstname'].' '.$sale['lastname']),'telephone'=>$sale['telephone'],'note'=>'Заказ сайта #'.$id,'items'=>$items,'cost_note'=>'Для заказа сайта себестоимость рассчитана по текущей себестоимости товара в X86 Stock. Способ оплаты можно выбрать вручную — аналитика пересчитается сразу.');
}
function x86_analytics_top_products($from='',$to='',$limit=20,$source='all') {
    x86_ensure_returns_table(); list($from,$to)=x86_analytics_range($from,$to);$source=in_array($source,array('all','stock','site'),true)?$source:'all';$limit=max(1,min(100,(int)$limit));$db=x86db();$map=array();
    if($source!=='site'){
        $si=x86p('x86stock_sale_item');$s=x86p('x86stock_sale');$rt=x86_returns_table();$sql="SELECT si.product_id,MAX(si.name) name,MAX(si.model) model,SUM(si.quantity) qty,SUM(si.line_total) revenue,SUM(si.profit_amount) profit FROM `$si` si JOIN `$s` s ON s.sale_id=si.sale_id WHERE s.date_created>=? AND s.date_created<DATE_ADD(?,INTERVAL 1 DAY) AND NOT EXISTS (SELECT 1 FROM `$rt` xr WHERE xr.source='stock' AND xr.reference_id=s.sale_id) GROUP BY si.product_id";$st=$db->prepare($sql);$st->bind_param('ss',$from,$to);$st->execute();$res=$st->get_result();while($r=$res->fetch_assoc()){$pid=(int)$r['product_id'];$map[$pid]=array('product_id'=>$pid,'name'=>$r['name'],'model'=>$r['model'],'qty'=>(int)$r['qty'],'revenue'=>(float)$r['revenue'],'profit'=>(float)$r['profit']);}$st->close();
    }
    if($source!=='stock'){
        x86_ensure_site_payment_table(); $o=x86p('order');$oh=x86p('order_history');$op=x86p('order_product');$xs=x86p('x86stock_product');$ov=x86_site_payment_table();$statusSql=x86_ocstore_status_sql();
        $sql="SELECT op.product_id,op.name,op.model,op.quantity,op.total,o.payment_code,o.payment_method,ov.payment_code x86_payment_code,COALESCE(xs.cost_price,0) unit_cost FROM `$op` op JOIN `$o` o ON o.order_id=op.order_id LEFT JOIN `$ov` ov ON ov.order_id=o.order_id LEFT JOIN `$xs` xs ON xs.product_id=op.product_id LEFT JOIN (SELECT order_id,MIN(date_added) sale_date FROM `$oh` WHERE order_status_id IN ($statusSql) GROUP BY order_id) sd ON sd.order_id=o.order_id WHERE o.order_status_id IN ($statusSql) AND COALESCE(sd.sale_date,o.date_added)>=? AND COALESCE(sd.sale_date,o.date_added)<DATE_ADD(?,INTERVAL 1 DAY)";
        $st=$db->prepare($sql);$st->bind_param('ss',$from,$to);$st->execute();$res=$st->get_result();while($r=$res->fetch_assoc()){$pid=(int)$r['product_id'];$qty=(int)$r['quantity'];$rev=(float)$r['total'];$comm=trim((string)$r['x86_payment_code'])!==''?x86_ocstore_commission_match($r['x86_payment_code'],''):x86_ocstore_commission_match($r['payment_code'],$r['payment_method']);$taxRate=x86_tax_rate_for_payment($comm['code'],$comm['name']);$profit=$rev-round($rev*(float)$comm['rate']/100,2)-round($rev*$taxRate/100,2)-((float)$r['unit_cost']*$qty);if(!isset($map[$pid]))$map[$pid]=array('product_id'=>$pid,'name'=>$r['name'],'model'=>$r['model'],'qty'=>0,'revenue'=>0.0,'profit'=>0.0);$map[$pid]['qty']+=$qty;$map[$pid]['revenue']+=$rev;$map[$pid]['profit']+=$profit;}$st->close();
    }
    $out=array_values($map);usort($out,function($a,$b){return (float)$b['profit']<=>(float)$a['profit'];});foreach($out as &$r){$r['revenue']=round((float)$r['revenue'],2);$r['profit']=round((float)$r['profit'],2);}unset($r);return array_slice($out,0,$limit);
}
function x86_analytics_chart($from='',$to='') {
    x86_ensure_returns_table(); list($from,$to)=x86_analytics_range($from,$to);$map=array();$start=strtotime($from);$end=strtotime($to);for($t=$start;$t<=$end;$t+=86400){$d=date('Y-m-d',$t);$map[$d]=array('date'=>$d,'sales'=>0.0,'profit'=>0.0);}
    $db=x86db();$s=x86p('x86stock_sale');$rt=x86_returns_table();$sql="SELECT DATE(date_created) d,SUM(gross_total) sales,SUM(profit_total) profit FROM `$s` s0 WHERE date_created>=? AND date_created<DATE_ADD(?,INTERVAL 1 DAY) AND NOT EXISTS (SELECT 1 FROM `$rt` xr WHERE xr.source='stock' AND xr.reference_id=s0.sale_id) GROUP BY DATE(date_created)";$st=$db->prepare($sql);$st->bind_param('ss',$from,$to);$st->execute();$res=$st->get_result();while($r=$res->fetch_assoc()){if(isset($map[$r['d']])){$map[$r['d']]['sales']+=(float)$r['sales'];$map[$r['d']]['profit']+=(float)$r['profit'];}}$st->close();
    foreach(x86_ocstore_sales_rows($from,$to,5000) as $r){$d=substr($r['sale_date'],0,10);if(isset($map[$d])){$map[$d]['sales']+=(float)$r['gross_total'];$map[$d]['profit']+=(float)$r['profit_total'];}}
    foreach($map as &$r){$r['sales']=round($r['sales'],2);$r['profit']=round($r['profit'],2);}unset($r);return array_values($map);
}
