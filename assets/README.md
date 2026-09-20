# Asset Folders

Drop the approved 2.5D stylized-realistic art into these folders using the
exact filenames referenced by `asset-manifest.json`. Nothing in the code
needs to change - `AssetManager` loads whatever `asset-manifest.json` points
to, and falls back to a neutral procedural placeholder shape for any asset
whose image file is missing, so the editor never crashes on missing art.

| Folder | Approved Phase 1 assets |
|---|---|
| `trees/` | `tree_large_01.png`, `tree_small_01.png` |
| `rocks/` | `rock_large_01.png`, `rock_small_01.png` |
| `vegetation/` | `bush_01.png`, `grass_cluster_01.png` |
| `fences/` | `fence_segment_01.png` |
| `barricades/` | `barricade_01.png` |
| `bridges/` | `bridge_wood_01.png` |
| `paths/` | `path_straight_01.png`, `path_curve_01.png`, `path_intersection_01.png` (reserved for a future tile-based path renderer - Phase 1 draws paths procedurally) |
| `terrain/` | `log_fallen_01.png`, `riverbank_01.png` |
| `bases/` | `base_player_marker.png`, `base_enemy_marker.png` |
| `buildings/`, `ui/` | reserved for later phases |

To add a new asset: drop the file in the right folder, add an entry to
`asset-manifest.json` (id, category, file, defaultScale, anchor,
dimensions), and it immediately becomes available in the matching editor
tool's asset palette.
