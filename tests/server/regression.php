<?php
// Fixed synthetic database in an isolated Docker network; no production config is loaded.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
define('X86STOCK_BOOTSTRAPPED', 1);
define('DB_PREFIX', 'test_');
define('DB_DATABASE', 'x86_test');
define('DB_PASSWORD', '');
$_SERVER['HTTP_HOST']='fixture.invalid';
$_SERVER['HTTP_USER_AGENT']='X86 regression fixture';
$db=new mysqli('x86-fixture-db','root','','x86_test');
$db->set_charset('utf8mb4');
$GLOBALS['x86db']=$db;
foreach(['base-schema.sql','reference/module-schema.sql'] as $schema){
    $db->multi_query(file_get_contents(__DIR__.'/'.$schema));
    do { if($r=$db->store_result())$r->free(); } while($db->more_results() && $db->next_result());
}
require __DIR__.'/reference/lib.php';
x86_ensure_returns_table();
function check($condition,$message){if(!$condition)throw new Exception($message);}
function closeTo($actual,$expected,$message){check(abs($actual-$expected)<0.0001,$message.': '.$actual.' != '.$expected);}
function reject($fn,$message){try{$fn();}catch(Throwable $e){return;}throw new Exception('Expected rejection: '.$message);}
function resetFixture(){
    $db=x86db();
    foreach(['product','product_description','stock_status','setting','language','product_special','product_attribute','product_to_category','category_description','x86stock_product','x86stock_setting','x86stock_commission','x86stock_sale','x86stock_sale_item','x86stock_purchase','x86stock_purchase_item','x86stock_movement','x86stock_auth','x86stock_return'] as $t)$db->query('TRUNCATE TABLE test_'.$t);
    $db->query("INSERT INTO test_language VALUES(1,'ru-ru',1,0)");
    $db->query("INSERT INTO test_stock_status VALUES(1,1,'В наличии')");
    $db->query("INSERT INTO test_product VALUES(1,'MODEL-1','PN-001',10,1000,1,1,NOW())");
    $db->query("INSERT INTO test_product_description VALUES(1,1,'Тестовый товар','Fixture brand','Synthetic product only')");
    $db->query("INSERT INTO test_x86stock_product VALUES(1,'CODE-1',400,800,1,20,'TEST',NOW())");
    $db->query("INSERT INTO test_x86stock_setting VALUES('tax_rate','3'),('tax_cash_enabled','1'),('partner_customer_group_id','0')");
    foreach(['cash'=>0,'kaspi_gold'=>4,'kaspi_red'=>10,'kaspi_qr_12'=>12,'kaspi_qr_24'=>14] as $code=>$rate)
        $db->query("INSERT INTO test_x86stock_commission(code,name,rate,active,sort_order) VALUES('$code','$code',$rate,1,0)");
    $_COOKIE=[];
}
$results=[];
foreach(['cash'=>0,'kaspi_gold'=>4,'kaspi_red'=>10,'kaspi_qr_12'=>12,'kaspi_qr_24'=>14] as $code=>$rate){
    resetFixture();$r=x86_make_sale([['product_id'=>1,'quantity'=>2]],$code);
    closeTo($r['gross_total'],2000,$code.' gross');closeTo($r['commission_total'],20*$rate,$code.' commission');
    closeTo($r['tax_total'],60,$code.' tax');closeTo($r['profit_total'],1140-20*$rate,$code.' profit');
    check((int)x86_product(1)['quantity']===8,$code.' stock');$results[]='sale '.$code;
}
resetFixture();$db->query("UPDATE test_x86stock_setting SET value='0' WHERE `key`='tax_cash_enabled'");
closeTo(x86_make_sale([['product_id'=>1,'quantity'=>2]],'cash')['tax_total'],0,'cash tax disabled');$results[]='cash tax setting';
resetFixture();$r=x86_make_sale([['product_id'=>1,'quantity'=>2]],'cash','partner');closeTo($r['gross_total'],1600,'partner price');$results[]='partner price';
resetFixture();$items=x86_cashier_sale_items([['product_id'=>1,'quantity'=>2,'unit_price'=>750]],'retail');
closeTo(x86_make_sale($items,'cash')['gross_total'],1500,'cashier editable price');$results[]='cashier custom unit_price';
resetFixture();reject(function(){x86_make_sale([['product_id'=>1,'quantity'=>11]],'cash');},'oversell');
check((int)x86_product(1)['quantity']===10,'oversell rollback');check((int)$db->query('SELECT COUNT(*) n FROM test_x86stock_sale')->fetch_assoc()['n']===0,'no failed sale persisted');$results[]='insufficient stock rollback';
resetFixture();reject(function(){x86_make_sale([['product_id'=>1,'quantity'=>1,'unit_price'=>-1]],'cash');},'negative price');$results[]='negative price';
resetFixture();$sale=x86_make_sale([['product_id'=>1,'quantity'=>2]],'cash');$r=x86_return_stock_sale($sale['sale_id']);
check((int)$r['restored_items']===2 && (int)x86_product(1)['quantity']===10,'return restores stock');
reject(function()use($sale){x86_return_stock_sale($sale['sale_id']);},'duplicate return');check((int)x86_product(1)['quantity']===10,'duplicate return unchanged');$results[]='return and duplicate rejection';
resetFixture();$r=x86_receive(1,2,700,'Fixture supplier');check((int)$r['qty_after']===12,'purchase quantity');closeTo($r['new_avg_cost'],450,'weighted cost');$results[]='purchase weighted cost';
resetFixture();$p=x86_product(1);check(isset($p['cost_price']),'admin cost');$cashier=x86_product_for_role($p,'cashier');check(!array_key_exists('cost_price',$cashier),'cashier cost hidden');check(!array_key_exists('location',$cashier),'cashier location hidden');$results[]='role-filtered product';
foreach(['MODEL-1','PN-001','CODE-1','Тестовый','brand'] as $q){check(count(x86_search_products($q,50))===1,'search '.$q);$results[]='search '.$q;}
$token=str_repeat('a',64);$hash=hash('sha256',$token);$db->query("INSERT INTO test_x86stock_auth(token_hash,user_agent,expires_at,date_created) VALUES('$hash','cashier|Fixture',DATE_ADD(NOW(),INTERVAL 1 DAY),NOW())");
$_COOKIE['X86STOCK_AUTH']=$token;check(x86_auth_user()['role']==='cashier','stored cashier role');$results[]='cashier session role';
$_COOKIE['X86STOCK_ROLE']='admin.invalid';check(x86_auth_user()['role']==='cashier','cashier stored role cannot be elevated');$results[]='role escalation rejected';
echo json_encode(['passed'=>count($results),'scenarios'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
