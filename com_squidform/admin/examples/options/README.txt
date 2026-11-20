SquidForm option files.

Put .json, .csv or .txt files here and refer to them from a field:
  "options": { "file": { "path": "sites.json" } }

JSON: a list of strings, or objects with "value" and "label" (extra keys can be used by filter_by).
CSV:  a header row; columns "value" and "label", plus any columns used by filter_by.
TXT:  one option per line; "value|label" to set a different label; lines starting with # are ignored.

Files outside this folder are refused. Change the folder in SquidForm > Options > Data Sources.
