-- Apply after import.php has loaded the dumps:  sqlite3 bhl.db < indexes-bhl.sql
--
-- No primary keys: page, item and part repeat their IDs (one page row per page type,
-- one item row per title, a few parts listed twice), so ID indexes are not unique.
-- SequenceOrder is only indexed alongside its parent, since on its own it means nothing.

CREATE INDEX IF NOT EXISTS "creator_idx1" ON creator(TitleID);
CREATE INDEX IF NOT EXISTS "creator_idx2" ON creator(CreatorID);
CREATE INDEX IF NOT EXISTS "creatoridentifier_idx1" ON creatoridentifier(CreatorID);
CREATE INDEX IF NOT EXISTS "creatoridentifier_idx2" ON creatoridentifier(IdentifierName);
CREATE INDEX IF NOT EXISTS "creatoridentifier_idx3" ON creatoridentifier(IdentifierValue COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS "doi_idx1" ON doi(EntityType, EntityID);
CREATE INDEX IF NOT EXISTS "doi_idx2" ON doi(DOI COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS "item_idx1" ON item(ItemID);
CREATE INDEX IF NOT EXISTS "item_idx2" ON item(TitleID);
CREATE INDEX IF NOT EXISTS "page_idx1" ON page(PageID);
CREATE INDEX IF NOT EXISTS "page_idx2" ON page(ItemID, SequenceOrder);
CREATE INDEX IF NOT EXISTS "part_idx1" ON part(PartID);
CREATE INDEX IF NOT EXISTS "part_idx2" ON part(ItemID);
CREATE INDEX IF NOT EXISTS "partcreator_idx1" ON partcreator(PartID);
CREATE INDEX IF NOT EXISTS "partcreator_idx2" ON partcreator(CreatorID);
CREATE INDEX IF NOT EXISTS "partidentifier_idx1" ON partidentifier(PartID);
CREATE INDEX IF NOT EXISTS "partidentifier_idx2" ON partidentifier(IdentifierName);
CREATE INDEX IF NOT EXISTS "partidentifier_idx3" ON partidentifier(IdentifierValue);
CREATE INDEX IF NOT EXISTS "partpage_idx1" ON partpage(PartID, SequenceOrder);
CREATE INDEX IF NOT EXISTS "partpage_idx2" ON partpage(PageID);
CREATE INDEX IF NOT EXISTS "partpage_idx3" ON partpage(ItemID);
CREATE INDEX IF NOT EXISTS "subject_idx1" ON subject(TitleID);
CREATE INDEX IF NOT EXISTS "title_idx1" ON title(TitleID);
CREATE INDEX IF NOT EXISTS "titleidentifier_idx1" ON titleidentifier(TitleID);
CREATE INDEX IF NOT EXISTS "titleidentifier_idx2" ON titleidentifier(IdentifierName);
CREATE INDEX IF NOT EXISTS "titleidentifier_idx3" ON titleidentifier(IdentifierValue);

ANALYZE;
