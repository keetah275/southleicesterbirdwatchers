# Master bird list

Source: `Potteric-Carr-Trip-List-2.pdf`, supplied by the administrator on
28 October 2026. The PDF describes the Potteric Carr trip of 17 October 2026.

The plugin bundles 263 unique names in
`app/public/wp-content/plugins/slb-trip-bird-lists/data/master-birds.txt`.
The order follows each PDF column down through all three pages, then proceeds
to the next column. Wrapped names were joined; PDF font ligatures were converted
to ordinary letters. Fulmar occurs in two columns and is retained at its first
occurrence only. Trip dates, weather, X marks, and totals are not master data.

Names and abbreviations are otherwise preserved. The source's **Girl Bunting**
has been corrected to **Cirl Bunting**, as confirmed by the administrator.

The list is seeded only when no master-list setting exists. Plugin upgrades do
not replace an existing master list, and existing trip snapshots are unaffected.
Edit names and ordering under **Events → Bird list settings** before starting
new trips. The PDF itself is not included in the plugin release or repository.
