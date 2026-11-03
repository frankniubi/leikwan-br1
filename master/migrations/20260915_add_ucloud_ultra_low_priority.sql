-- UCloud relay material has repeated provider-connect timeouts. Keep it
-- available as a fallback, but dispatch normal-provider material first.
UPDATE ultra_lines
SET dispatch_class = 'low'
WHERE LOWER(COALESCE(provider, '')) LIKE '%ucloud%'
   OR LOWER(COALESCE(source_title, '')) LIKE '%ucloud%'
   OR provider LIKE '%优云%'
   OR source_title LIKE '%优云%';
