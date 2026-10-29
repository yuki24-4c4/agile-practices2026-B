-- Webアプリ用DBユーザーの権限を制限
REVOKE ALL PRIVILEGES, GRANT OPTION
FROM 'campus_app'@'%';

GRANT SELECT, INSERT, UPDATE, DELETE
ON campus_reservation.*
TO 'campus_app'@'%';