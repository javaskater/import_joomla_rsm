#!/bin/bash

mon_repertoire=$(pwd)
nom_module=$(basename $mon_repertoire)
branche=$(git branch | sed -n '/\* /s///p')
git archive --format zip --prefix="${nom_module}/" --output ../"${nom_module}.zip" $branche
echo "archive: ${nom_module}.zip générée (dans le répertoire parent) pour la branche: ${branche}; contenu:"
unzip -l ../"${nom_module}.zip"
