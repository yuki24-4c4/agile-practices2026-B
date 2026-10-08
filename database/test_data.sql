-- Sample records for the four-table school reservation demo.
-- Run schema.sql first. Re-running this script updates the same sample IDs.
-- Demo password for all users: DemoPass123! (test environment only).
USE campus_reservation;
START TRANSACTION;

INSERT INTO users (id,name,email,password,role) VALUES
(1,'山田太郎','yamada@example.com','$2y$12$vECZbZsJMyB1BgQD6cCybeI9j2wVHN00ntKM651jxuKHfs/PBTYka','user'),
(2,'佐藤花子','sato@example.com','$2y$12$vECZbZsJMyB1BgQD6cCybeI9j2wVHN00ntKM651jxuKHfs/PBTYka','user'),
(3,'管理者A','admin@example.com','$2y$12$vECZbZsJMyB1BgQD6cCybeI9j2wVHN00ntKM651jxuKHfs/PBTYka','admin')
ON DUPLICATE KEY UPDATE name=VALUES(name),email=VALUES(email),password=VALUES(password),role=VALUES(role);

INSERT INTO categories (id,name) VALUES
(1,'普通教室'),(2,'PC関連'),(3,'音響機器')
ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT INTO items (id,category_id,name,description,status) VALUES
(1,1,'101教室','定員30名','available'),
(2,1,'102教室','定員40名','available'),
(3,2,'ノートPC A','Windows11搭載','available'),
(4,3,'ワイヤレスマイクA','無線接続対応','maintenance')
ON DUPLICATE KEY UPDATE category_id=VALUES(category_id),name=VALUES(name),description=VALUES(description),status=VALUES(status);

INSERT INTO reservations (id,user_id,item_id,start_date,end_date,status) VALUES
(1,1,1,'2026-10-12','2026-10-12','pending'),
(2,2,2,'2026-10-13','2026-10-13','approved'),
(3,1,3,'2026-10-14','2026-10-16','pending'),
(4,2,1,'2026-09-20','2026-09-20','returned'),
(5,1,2,'2026-10-20','2026-10-20','rejected')
ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),item_id=VALUES(item_id),start_date=VALUES(start_date),end_date=VALUES(end_date),status=VALUES(status);

COMMIT;
