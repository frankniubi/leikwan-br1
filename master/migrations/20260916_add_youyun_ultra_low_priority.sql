-- Existing inventory names the UCloud pool as 优云. Use UTF-8 bytes so this
-- migration remains portable even when the SQL client locale is not UTF-8.
UPDATE ultra_lines
SET dispatch_class = 'low'
WHERE provider LIKE CONCAT('%', CONVERT(0xE4BC98E4BA91 USING utf8mb4), '%')
   OR source_title LIKE CONCAT('%', CONVERT(0xE4BC98E4BA91 USING utf8mb4), '%');
