<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
ini_set('display_errors', '0');
session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();
function esc(mixed $s): string { return htmlspecialchars((string)($s??''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_NAME').';charset=utf8mb4', (string)getenv('DB_USER'), (string)getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    }
    return $pdo;
}
function q(string $sql, array $args=[]): PDOStatement { $s=db()->prepare($sql); $s->execute($args); return $s; }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(24)); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="'.esc(csrf()).'">'; }
function redirect(string $page='items'): never { header('Location: /?page='.rawurlencode($page)); exit; }
function flash(string $msg, bool $error=false): never { $_SESSION['flash']=['message'=>$msg,'error'=>$error]; redirect($_POST['back']??'items'); }
function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    $u=q('SELECT id,name,email,role FROM users WHERE id=?',[(int)$_SESSION['uid']])->fetch();
    return $u?:null;
}
function require_auth(?array $u): void { if (!$u) { http_response_code(403); throw new RuntimeException('ログインしてください。'); } }
function require_admin(?array $u): void { require_auth($u); if ($u['role'] !== 'admin') {http_response_code(403); throw new RuntimeException('管理者専用の操作です。');} }
function valid_date(string $v): bool { $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v); return $d!==false && $d->format('Y-m-d')===$v; }
function valid_interval(string $a,string $b): bool { return valid_date($a)&&valid_date($b)&&$b>=$a; }
function stmt_is_busy(int $item, string $start, string $end, int $except=0): bool {
    return (bool)q("SELECT id FROM reservations WHERE item_id=? AND id<>? AND status IN ('pending','approved','lent') AND start_date<=? AND end_date>=? LIMIT 1",[$item,$except,$end,$start])->fetch();
}
function status_name(string $s): string { return ['available'=>'利用可能','maintenance'=>'メンテナンス','inactive'=>'利用停止','pending'=>'申請中','approved'=>'承認済み','lent'=>'貸出中','returned'=>'返却済み','rejected'=>'却下','canceled'=>'キャンセル'][$s]??$s; }
function action(string $name, array $u): void {
    if ($name==='logout') { $_SESSION=[]; session_regenerate_id(true); flash('ログアウトしました。'); }
    if ($name==='reserve') {
        $id=(int)($_POST['item_id']??0); $a=trim((string)($_POST['start_date']??'')); $b=trim((string)($_POST['end_date']??''));
        if (!valid_interval($a,$b) || $a < date('Y-m-d')) throw new RuntimeException('今日以降の正しい予約期間を入力してください。');
        $pdo=db(); $pdo->beginTransaction();
        try {
            $item=q('SELECT id,status FROM items WHERE id=? FOR UPDATE',[$id])->fetch();
            if (!$item || $item['status']!=='available') throw new RuntimeException('この施設・備品は予約できません。');
            if (stmt_is_busy($id,$a,$b)) throw new RuntimeException('その期間はすでに予約されています。');
            q("INSERT INTO reservations (user_id,item_id,start_date,end_date,status) VALUES (?,?,?,?,'pending')",[$u['id'],$id,$a,$b]);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        flash('予約申請を受け付けました。');
    }
    if ($name==='cancel') {
        $id=(int)($_POST['id']??0);
        $s=q("UPDATE reservations SET status='canceled' WHERE id=? AND user_id=? AND status IN ('pending','approved')",[$id,$u['id']]);
        if (!$s->rowCount()) throw new RuntimeException('この予約はキャンセルできません。');
        flash('予約をキャンセルしました。');
    }
    if ($name==='change_reservation') {
        require_admin($u); $id=(int)($_POST['id']??0); $next=(string)($_POST['status']??'');
        $transitions=['pending'=>['approved','rejected'],'approved'=>['lent','canceled'],'lent'=>['returned']];
        $pdo=db(); $pdo->beginTransaction();
        try {
            $r=q('SELECT * FROM reservations WHERE id=? FOR UPDATE',[$id])->fetch();
            if (!$r || !in_array($next,$transitions[$r['status']]??[],true)) throw new RuntimeException('許可されていない状態変更です。');
            q('SELECT id FROM items WHERE id=? FOR UPDATE',[$r['item_id']]);
            if (in_array($next,['approved','lent'],true)) {
                $it=q('SELECT status FROM items WHERE id=?',[$r['item_id']])->fetch();
                if ($it['status']!=='available') throw new RuntimeException('使用できない備品です。');
                if (stmt_is_busy((int)$r['item_id'],$r['start_date'],$r['end_date'],$id)) throw new RuntimeException('予約期間が重複しています。');
            }
            q('UPDATE reservations SET status=? WHERE id=?',[$next,$id]);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        flash('予約状態を変更しました。');
    }
    if ($name==='save_category') {
        require_admin($u); $v=trim((string)($_POST['name']??''));
        if ($v==='' || strlen($v)>300) throw new RuntimeException('カテゴリー名は1〜100文字で入力してください。');
        q('INSERT INTO categories (name) VALUES (?)',[$v]); flash('カテゴリーを登録しました。');
    }
    if ($name==='save_item') {
        require_admin($u); $id=(int)($_POST['id']??0); $cat=(int)($_POST['category_id']??0); $name=trim((string)($_POST['name']??'')); $desc=trim((string)($_POST['description']??'')); $status=(string)($_POST['status']??'available');
        if ($name==='' || strlen($name)>300 || !in_array($status,['available','maintenance','inactive'],true)) throw new RuntimeException('入力内容を確認してください。');
        if (!q('SELECT id FROM categories WHERE id=?',[$cat])->fetch()) throw new RuntimeException('カテゴリーが存在しません。');
        if ($id) {
            if ($status!=='available' && q("SELECT id FROM reservations WHERE item_id=? AND status IN ('pending','approved','lent') LIMIT 1",[$id])->fetch()) throw new RuntimeException('進行中の予約があるため停止できません。');
            q('UPDATE items SET category_id=?, name=?, description=?, status=? WHERE id=?',[$cat,$name,$desc,$status,$id]);
        } else q('INSERT INTO items (category_id,name,description,status) VALUES (?,?,?,?)',[$cat,$name,$desc,$status]);
        flash('施設・備品を保存しました。');
    }
    throw new RuntimeException('不明な操作です。');
}
$page=(string)($_GET['page']??'items');
if (in_array($page,['account','reserve','mine','manage_reservations','manage_items'],true) && empty($_SESSION['uid'])) { header('Location: /?page=login'); exit; }
if (in_array($page,['manage_reservations','manage_items'],true) && !empty($_SESSION['uid'])) { $role=q('SELECT role FROM users WHERE id=?',[(int)$_SESSION['uid']])->fetchColumn(); if ($role!=='admin') { http_response_code(403); echo '管理者専用のページです。'; exit; } }
$u=null; $error=null;
try {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!hash_equals(csrf(),(string)($_POST['csrf']??''))) throw new RuntimeException('セッションの有効期限が切れました。ページを更新してください。');
        $a=(string)($_POST['action']??'');
        if ($a==='login') {
            $row=q('SELECT * FROM users WHERE email=?',[trim((string)($_POST['email']??''))])->fetch();
            if (!$row || !password_verify((string)($_POST['password']??''),$row['password'])) throw new RuntimeException('メールアドレスまたはパスワードが違います。');
            session_regenerate_id(true); $_SESSION['uid']=$row['id']; flash('ログインしました。');
        }
        $u=current_user();
        if ($a==='logout') { action($a,[]); }
        require_auth($u); action($a,$u);
    }
    $u=current_user();
} catch (Throwable $e) { $error=$e instanceof PDOException?'データベースエラーが発生しました。起動状態を確認してください。':$e->getMessage(); }
$notice=$_SESSION['flash']??null; unset($_SESSION['flash']);
try {
    $cats=q('SELECT * FROM categories ORDER BY id')->fetchAll();
    $items=q('SELECT i.*,c.name AS category_name FROM items i JOIN categories c ON c.id=i.category_id ORDER BY i.id')->fetchAll();
} catch (Throwable $e) { $cats=[];$items=[];$error='データベースの起動が完了していません。数秒後に再読み込みしてください。'; }
function f(string $act,string $back, string $button, string $inner='', string $class=''): void {echo '<form method="post" class="'.esc($class).'">'.csrf_field().'<input type="hidden" name="action" value="'.esc($act).'"><input type="hidden" name="back" value="'.esc($back).'">'.$inner.'<button type="submit">'.esc($button).'</button></form>';}
?><!doctype html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>学内施設・備品予約管理</title><style>
:root{font-family:system-ui,-apple-system,"Yu Gothic",Meiryo,sans-serif;color:#1d2939;background:#f3f6fb}*{box-sizing:border-box}body{margin:0}header{background:#17365d;color:white;padding:22px max(4vw,20px)}h1{margin:0 0 6px;font-size:1.5rem}h2{font-size:1.25rem}header p{margin:0;color:#d7e3f3}nav{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}nav a{color:white;text-decoration:none;background:#ffffff26;padding:9px 14px;border-radius:6px}nav a:hover{background:#ffffff44}main{margin:28px auto;max-width:1200px;padding:0 20px}.card{background:white;padding:23px;margin-bottom:20px;border-radius:10px;box-shadow:0 2px 12px #182e4615}table{border-collapse:collapse;width:100%;font-size:.92rem}th,td{padding:12px;border-bottom:1px solid #dce2eb;text-align:left;vertical-align:top}th{background:#f0f4f9}input,select,textarea{padding:10px;border:1px solid #aebbd0;border-radius:6px;max-width:100%;font:inherit}input[type=date]{width:165px}button{background:#215a9c;color:white;padding:10px 14px;border:0;border-radius:6px;cursor:pointer;font:inherit}button:hover{background:#143c6d}label{display:block;margin:10px 0;font-weight:600}label input,label select,label textarea{display:block;width:100%;margin-top:5px}form.inline{display:inline-flex;gap:6px;align-items:center;margin:3px}form.inline button{font-size:.85rem}.muted{color:#617287}.alert{padding:13px 18px;border-radius:6px;background:#e2f4e9;margin-bottom:16px}.error{background:#ffe5e2}.scroll{overflow-x:auto}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}.pill{background:#e9effa;padding:4px 9px;border-radius:20px;display:inline-block;font-size:.85rem}footer{text-align:center;padding:32px;color:#66788b}a{color:#215a9c}
</style></head><body><header><h1>学内施設・備品予約管理システム</h1><p>PHP × MySQL × Docker</p><nav><a href="/?page=items">施設・備品一覧</a><?php if($u): ?><a href="/?page=mine">自分の予約</a><?php if($u['role']==='admin'): ?><a href="/?page=manage_reservations">予約管理</a><a href="/?page=manage_items">備品管理</a><?php endif; ?><a href="/?page=account">アカウント</a><?php else: ?><a href="/?page=login">ログイン</a><?php endif; ?></nav></header><main>
<?php if($notice): ?><div class="alert<?=!empty($notice['error'])?' error':''?>"><?=esc($notice['message'])?></div><?php endif; ?><?php if($error): ?><div class="alert error"><?=esc($error)?></div><?php endif; ?>
<?php if($page==='login'): ?>
<section class="card"><h2>ログイン</h2><?php if($u): ?><p>すでにログインしています。</p><?php else: ?><form method="post" style="max-width:440px"><?=csrf_field()?><input type="hidden" name="action" value="login"><label>メールアドレス<input type="email" name="email" required autocomplete="username"></label><label>パスワード<input type="password" name="password" required autocomplete="current-password"></label><button>ログイン</button></form><p class="muted">デモ用アカウントは README.md を参照してください。</p><?php endif; ?></section>
<?php elseif($page==='account'): require_auth($u); ?>
<section class="card"><h2>アカウント</h2><p>氏名：<?=esc($u['name'])?></p><p>メール：<?=esc($u['email'])?></p><p>権限：<?=esc($u['role']==='admin'?'管理者':'一般利用者')?></p><?php f('logout','account','ログアウト'); ?></section>
<?php elseif($page==='items'): ?>
<section class="card"><h2>施設・備品一覧</h2><div class="scroll"><table><thead><tr><th>ID</th><th>名前</th><th>カテゴリー</th><th>説明</th><th>状態</th><th>予約</th></tr></thead><tbody><?php foreach($items as $i): ?><tr><td><?=esc($i['id'])?></td><td><?=esc($i['name'])?></td><td><?=esc($i['category_name'])?></td><td><?=esc($i['description'])?></td><td><span class="pill"><?=esc(status_name($i['status']))?></span></td><td><?php if($i['status']==='available' && $u): ?><a href="/?page=reserve&id=<?=esc($i['id'])?>">予約する →</a><?php elseif(!$u): ?><a href="/?page=login">ログインして予約</a><?php else: ?>予約不可<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php elseif($page==='reserve'): require_auth($u); $item=null; foreach($items as $i){if((int)$i['id']===(int)($_GET['id']??0)) $item=$i;} if(!$item||$item['status']!=='available') throw new RuntimeException('予約できる備品が見つかりません。'); ?>
<section class="card"><h2>予約申請：<?=esc($item['name'])?></h2><p>日付は開始日・終了日ともに含まれます。重複する申請がある期間は予約できません。</p><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="reserve"><input type="hidden" name="back" value="items"><input type="hidden" name="item_id" value="<?=esc($item['id'])?>"><div class="grid"><label>利用開始日<input type="date" name="start_date" min="<?=date('Y-m-d')?>" required></label><label>利用終了日<input type="date" name="end_date" min="<?=date('Y-m-d')?>" required></label></div><button>予約を申請</button></form></section>
<?php elseif($page==='mine'): require_auth($u); $rows=q('SELECT r.*,i.name AS item_name FROM reservations r JOIN items i ON i.id=r.item_id WHERE r.user_id=? ORDER BY r.id DESC',[$u['id']])->fetchAll(); ?>
<section class="card"><h2>自分の予約履歴</h2><div class="scroll"><table><tr><th>予約ID</th><th>施設・備品</th><th>期間</th><th>状況</th><th>操作</th></tr><?php foreach($rows as $r): ?><tr><td><?=esc($r['id'])?></td><td><?=esc($r['item_name'])?></td><td><?=esc($r['start_date'])?> ～ <?=esc($r['end_date'])?></td><td><?=esc(status_name($r['status']))?></td><td><?php if(in_array($r['status'],['pending','approved'],true)): ?><?php f('cancel','mine','キャンセル','<input type="hidden" name="id" value="'.esc($r['id']).'">','inline'); ?><?php endif; ?></td></tr><?php endforeach; ?></table></div></section>
<?php elseif($page==='manage_reservations'): require_admin($u); $rows=q('SELECT r.*,u.name AS user_name,i.name AS item_name FROM reservations r JOIN users u ON u.id=r.user_id JOIN items i ON i.id=r.item_id ORDER BY r.id DESC')->fetchAll(); $tr=['pending'=>['approved','rejected'],'approved'=>['lent','canceled'],'lent'=>['returned']]; ?>
<section class="card"><h2>予約管理（管理者）</h2><div class="scroll"><table><tr><th>ID</th><th>申請者</th><th>施設・備品</th><th>期間</th><th>状態</th><th>操作</th></tr><?php foreach($rows as $r): ?><tr><td><?=esc($r['id'])?></td><td><?=esc($r['user_name'])?></td><td><?=esc($r['item_name'])?></td><td><?=esc($r['start_date'])?> ～ <?=esc($r['end_date'])?></td><td><?=esc(status_name($r['status']))?></td><td><?php foreach($tr[$r['status']]??[] as $to): ?><?php f('change_reservation','manage_reservations',status_name($to),'<input type="hidden" name="id" value="'.esc($r['id']).'"><input type="hidden" name="status" value="'.esc($to).'">','inline'); ?><?php endforeach; ?></td></tr><?php endforeach; ?></table></div></section>
<?php elseif($page==='manage_items'): require_admin($u); ?>
<section class="card"><h2>カテゴリーを追加</h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_category"><input type="hidden" name="back" value="manage_items"><input name="name" required maxlength="100" placeholder="カテゴリー名"><button>追加</button></form></section>
<section class="card"><h2>施設・備品を追加</h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_item"><input type="hidden" name="back" value="manage_items"><div class="grid"><label>名前<input name="name" maxlength="100" required></label><label>カテゴリー<select name="category_id" required><?php foreach($cats as $c): ?><option value="<?=esc($c['id'])?>"><?=esc($c['name'])?></option><?php endforeach; ?></select></label><label>状態<select name="status"><option value="available">利用可能</option><option value="maintenance">メンテナンス</option><option value="inactive">利用停止</option></select></label></div><label>説明<textarea name="description" rows="2"></textarea></label><button>追加</button></form></section>
<section class="card"><h2>施設・備品を編集</h2><?php foreach($items as $i): ?><details style="border-bottom:1px solid #ddd;padding:12px"><summary><?=esc($i['name'])?>（<?=esc(status_name($i['status']))?>）</summary><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_item"><input type="hidden" name="back" value="manage_items"><input type="hidden" name="id" value="<?=esc($i['id'])?>"><div class="grid"><label>名前<input name="name" value="<?=esc($i['name'])?>" maxlength="100" required></label><label>カテゴリー<select name="category_id"><?php foreach($cats as $c): ?><option value="<?=esc($c['id'])?>" <?=$c['id']===$i['category_id']?'selected':''?>><?=esc($c['name'])?></option><?php endforeach; ?></select></label><label>状態<select name="status"><?php foreach(['available','maintenance','inactive'] as $s): ?><option value="<?=esc($s)?>" <?=$s===$i['status']?'selected':''?>><?=esc(status_name($s))?></option><?php endforeach; ?></select></label></div><label>説明<textarea name="description"><?=esc($i['description'])?></textarea></label><button>保存</button></form></details><?php endforeach; ?></section>
<?php else: ?><section class="card"><h2>ページが見つかりません</h2><a href="/">一覧へ戻る</a></section><?php endif; ?>
</main><footer>授業用サンプルアプリ・個人情報や実運用データは登録しないでください。</footer></body></html>
