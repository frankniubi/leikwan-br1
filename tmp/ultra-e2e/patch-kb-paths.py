import pathlib
p = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1\tmp\ultra-e2e\publish-kb-headless.py")
t = p.read_text(encoding="utf-8")
t = t.replace('shutil.copy2(ROOT / "migrations/20260729_seed_multilingual_knowledgebase.sql", BACKUP / "seed.prev.sql")',
              'existing_seed = ROOT / "master/migrations/20260729_seed_multilingual_knowledgebase.sql"\nif existing_seed.is_file():\n    shutil.copy2(existing_seed, BACKUP / "seed.prev.sql")')
t = t.replace('shutil.copy2(seed, ROOT / "migrations/20260729_seed_multilingual_knowledgebase.sql")',
              'shutil.copy2(seed, ROOT / "master/migrations/20260729_seed_multilingual_knowledgebase.sql")')
p.write_text(t, encoding="utf-8")
print("patched seed paths")
