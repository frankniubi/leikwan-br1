INSERT INTO ultra_zones
    (zone_code, zone_role, label, aliases_json, sort_order, is_active, created_at, updated_at)
VALUES
    ('fuzhou', 'ingress', 'Fuzhou', '["福州","福州市","Fuzhou"]', 40, 1, NOW(), NOW()),
    ('guiyang', 'ingress', 'Guiyang', '["贵阳","貴陽","贵阳市","Guiyang"]', 50, 1, NOW(), NOW()),
    ('south-korea', 'destination', 'South Korea', '["韩国","韓國","South Korea","Korea"]', 70, 1, NOW(), NOW()),
    ('taiwan', 'destination', 'Taiwan', '["台湾","台灣","Taiwan"]', 80, 1, NOW(), NOW()),
    ('us-east', 'destination', 'US East', '["美东","美東","US East"]', 90, 1, NOW(), NOW()),
    ('sydney', 'destination', 'Sydney', '["悉尼","Sydney"]', 100, 1, NOW(), NOW()),
    ('melbourne', 'destination', 'Melbourne', '["墨尔本","墨爾本","Melbourne"]', 110, 1, NOW(), NOW()),
    ('beijing', 'destination', 'Beijing', '["北京","北京市","Beijing"]', 200, 1, NOW(), NOW()),
    ('shanghai', 'destination', 'Shanghai', '["上海","上海市","Shanghai"]', 210, 1, NOW(), NOW()),
    ('guangzhou', 'destination', 'Guangzhou', '["广州","廣州","广州市","Guangzhou"]', 220, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    aliases_json = VALUES(aliases_json),
    sort_order = VALUES(sort_order),
    is_active = VALUES(is_active),
    updated_at = VALUES(updated_at);

UPDATE ultra_lines
SET auto_ingress_zone = CASE catalog_ingress_label
        WHEN '福州' THEN 'fuzhou'
        WHEN '贵阳' THEN 'guiyang'
        ELSE auto_ingress_zone
    END,
    updated_at = NOW()
WHERE catalog_ingress_label IN ('福州', '贵阳');

UPDATE ultra_lines
SET auto_destination_zone = CASE
        WHEN catalog_destination_label LIKE '韩国%' THEN 'south-korea'
        WHEN catalog_destination_label IN ('台湾', '台灣') THEN 'taiwan'
        WHEN catalog_destination_label IN ('美东', '美東') THEN 'us-east'
        WHEN catalog_destination_label = '悉尼' THEN 'sydney'
        WHEN catalog_destination_label IN ('墨尔本', '墨爾本') THEN 'melbourne'
        WHEN catalog_destination_label = '北京' THEN 'beijing'
        WHEN catalog_destination_label = '上海' THEN 'shanghai'
        WHEN catalog_destination_label = '广州' THEN 'guangzhou'
        ELSE auto_destination_zone
    END,
    updated_at = NOW()
WHERE catalog_destination_label LIKE '韩国%'
   OR catalog_destination_label IN ('台湾', '台灣', '美东', '美東', '悉尼', '墨尔本', '墨爾本', '北京', '上海', '广州');

UPDATE ultra_lines
SET admin_ingress_zone = 'guangzhou',
    admin_destination_zone = 'unknown',
    updated_at = NOW()
WHERE line_id = 347
  AND source_title = 'TCP-游戏下载'
  AND admin_ingress_zone IS NULL
  AND admin_destination_zone IS NULL;
