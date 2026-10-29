
SET NAMES utf8mb4;
USE campus_reservation;
START TRANSACTION;

INSERT INTO users (id,name,email,password,role) VALUES
(1,'山田太郎','yamada@example.com','$2y$12$vECZbZsJMyB1BgQD6cCybeI9j2wVHN00ntKM651jxuKHfs/PBTYka','user'),
(2,'佐藤花子','sato@example.com','$2y$12$vECZbZsJMyB1BgQD6cCybeI9j2wVHN00ntKM651jxuKHfs/PBTYka','user'),
(3,'管理者A','admin@example.com','$2y$12$vECZbZsJMyB1BgQD6cCybeI9j2wVHN00ntKM651jxuKHfs/PBTYka','admin'),
(4,'鈴木一郎','suzuki@example.com','$2y$12$vECZbZsJMyB1BgQD6cCybeI9j2wVHN00ntKM651jxuKHfs/PBTYka','user'),
(5,'高橋美咲','takahashi@example.com','$2y$12$vECZbZsJMyB1BgQD6cCybeI9j2wVHN00ntKM651jxuKHfs/PBTYka','user'),
(6,'田中健太','tanaka@example.com','$2y$12$vECZbZsJMyB1BgQD6cCybeI9j2wVHN00ntKM651jxuKHfs/PBTYka','user'),
(7,'伊藤彩花','ito@example.com','$2y$12$vECZbZsJMyB1BgQD6cCybeI9j2wVHN00ntKM651jxuKHfs/PBTYka','user'),
(8,'渡辺翔太','watanabe@example.com','$2y$12$vECZbZsJMyB1BgQD6cCybeI9j2wVHN00ntKM651jxuKHfs/PBTYka','user'),
(9,'小林結衣','kobayashi@example.com','$2y$12$vECZbZsJMyB1BgQD6cCybeI9j2wVHN00ntKM651jxuKHfs/PBTYka','user'),
(10,'加藤大輝','kato@example.com','$2y$12$vECZbZsJMyB1BgQD6cCybeI9j2wVHN00ntKM651jxuKHfs/PBTYka','user'),
(11,'吉田葵','yoshida@example.com','$2y$12$vECZbZsJMyB1BgQD6cCybeI9j2wVHN00ntKM651jxuKHfs/PBTYka','user'),
(12,'中村陽菜','nakamura@example.com','$2y$12$vECZbZsJMyB1BgQD6cCybeI9j2wVHN00ntKM651jxuKHfs/PBTYka','user'),
(13,'管理者B','admin2@example.com','$2y$12$vECZbZsJMyB1BgQD6cCybeI9j2wVHN00ntKM651jxuKHfs/PBTYka','admin')
ON DUPLICATE KEY UPDATE
name=VALUES(name),
email=VALUES(email),
password=VALUES(password),
role=VALUES(role);

INSERT INTO categories (id,name) VALUES
(1,'普通教室'),
(2,'PC関連'),
(3,'音響機器'),
(4,'会議室'),
(5,'映像機器'),
(6,'撮影機材'),
(7,'ネットワーク機器'),
(8,'その他備品')
ON DUPLICATE KEY UPDATE
name=VALUES(name);

INSERT INTO items (id,category_id,name,description,storage_location,status) VALUES
(1,1,'101教室','定員30名','1号館1階','available'),
(2,1,'102教室','定員40名','1号館1階','available'),
(3,2,'ノートPC A','Windows11搭載','情報管理室','available'),
(4,3,'ワイヤレスマイクA','無線接続対応','音響機材庫','maintenance'),
(5,1,'201教室','定員45名','1号館2階','available'),
(6,1,'202教室','定員30名・プロジェクター付き','1号館2階','available'),
(7,4,'第1会議室','定員12名','2号館1階','available'),
(8,4,'第2会議室','定員8名','2号館2階','available'),
(9,2,'ノートPC B','Windows11搭載','情報管理室','available'),
(10,2,'ノートPC C','MacBook Air','情報管理室','available'),
(11,2,'タブレット A','iPad・Wi-Fiモデル','情報管理室','available'),
(12,3,'ワイヤレスマイクB','無線接続対応','音響機材庫','available'),
(13,3,'スピーカー A','ポータブル型','音響機材庫','available'),
(14,3,'ミキサー A','8チャンネル','音響機材庫','available'),
(15,5,'プロジェクター A','HDMI入力対応','視聴覚準備室','available'),
(16,5,'スクリーン A','移動式100インチ','視聴覚準備室','available'),
(17,5,'大型モニター A','55インチ','2号館倉庫','available'),
(18,6,'デジタルカメラ A','ミラーレス一眼','撮影機材庫','available'),
(19,6,'三脚 A','高さ調整可能','撮影機材庫','available'),
(20,6,'LEDライト A','撮影用照明','撮影機材庫','maintenance'),
(21,7,'Wi-Fiルーター A','学内検証用','情報管理室','available'),
(22,7,'LANハブ A','8ポート','情報管理室','available'),
(23,8,'延長コード A','5m・6口','備品倉庫','available'),
(24,8,'ホワイトボード A','移動式','備品倉庫','inactive')
ON DUPLICATE KEY UPDATE
category_id=VALUES(category_id),
name=VALUES(name),
description=VALUES(description),
storage_location=VALUES(storage_location),
status=VALUES(status);

INSERT INTO reservations (id,user_id,item_id,start_date,end_date,status) VALUES
(1,1,1,'2026-10-12','2026-10-12','pending'),
(2,2,2,'2026-10-13','2026-10-13','approved'),
(3,1,3,'2026-10-14','2026-10-16','pending'),
(4,2,1,'2026-09-20','2026-09-20','returned'),
(5,1,2,'2026-10-20','2026-10-20','rejected'),
(6,4,5,'2026-09-10','2026-09-10','returned'),
(7,5,7,'2026-09-18','2026-09-18','returned'),
(8,6,9,'2026-10-01','2026-10-03','returned'),
(9,7,13,'2026-10-07','2026-10-07','returned'),
(10,8,15,'2026-10-09','2026-10-09','returned'),
(11,9,18,'2026-10-21','2026-10-22','returned'),
(12,10,10,'2026-10-24','2026-10-26','returned'),
(13,11,17,'2026-10-27','2026-10-27','returned'),
(14,12,23,'2026-10-28','2026-10-28','returned'),
(15,4,6,'2026-10-29','2026-10-29','approved'),
(16,5,11,'2026-10-29','2026-10-31','lent'),
(17,6,12,'2026-10-29','2026-10-30','lent'),
(18,7,8,'2026-10-30','2026-10-30','pending'),
(19,8,14,'2026-10-31','2026-10-31','approved'),
(20,9,16,'2026-11-01','2026-11-01','pending'),
(21,10,19,'2026-11-02','2026-11-03','approved'),
(22,11,21,'2026-11-04','2026-11-05','pending'),
(23,12,22,'2026-11-06','2026-11-06','approved'),
(24,4,9,'2026-11-07','2026-11-08','pending'),
(25,5,5,'2026-11-09','2026-11-09','rejected'),
(26,6,7,'2026-11-10','2026-11-10','canceled'),
(27,7,18,'2026-11-11','2026-11-12','approved'),
(28,8,23,'2026-11-13','2026-11-13','pending'),
(29,9,13,'2026-11-14','2026-11-14','approved'),
(30,10,15,'2026-11-15','2026-11-15','canceled'),
(31,11,10,'2026-11-16','2026-11-18','pending'),
(32,12,17,'2026-11-19','2026-11-19','approved'),
(33,4,6,'2026-11-20','2026-11-20','pending'),
(34,5,19,'2026-11-21','2026-11-21','approved'),
(35,6,8,'2026-11-22','2026-11-22','rejected')
ON DUPLICATE KEY UPDATE
user_id=VALUES(user_id),
item_id=VALUES(item_id),
start_date=VALUES(start_date),
end_date=VALUES(end_date),
status=VALUES(status);

COMMIT;
