# Documentation — Help Me Stage

Documents de conception de la base de données (14 tables, base Railway).

## Sommaire

| Dossier | Contenu | Fichiers |
|---------|---------|----------|
| [MCD/](MCD/) | **Modèle Conceptuel de Données** — entités, associations, cardinalités | [MCD.md](MCD/MCD.md) · [mcd.png](MCD/mcd.png) · [mcd.svg](MCD/mcd.svg) · [mcd.dot](MCD/mcd.dot) |
| [MLD/](MLD/) | **Modèle Logique de Données** — tables, clés primaires/étrangères | [MLD.md](MLD/MLD.md) · [mld.png](MLD/mld.png) · [mld.svg](MLD/mld.svg) · [mld.dot](MLD/mld.dot) |

## Régénérer les diagrammes

Les images sont produites avec **Graphviz** à partir des fichiers `.dot` :

```bash
# MCD
dot -Tpng docs/MCD/mcd.dot -o docs/MCD/mcd.png
# MLD
dot -Tpng docs/MLD/mld.dot -o docs/MLD/mld.png
```

## Cohérence

Les deux modèles ont été vérifiés comme **conformes à 100 %** à la base de données
réelle : les 14 tables et l'ensemble de leurs colonnes et clés étrangères y figurent.
