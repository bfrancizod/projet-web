/**
 * Recherche en temps réel dans la liste des compétences du formulaire d'offre.
 *
 * Filtre les cases à cocher selon la saisie de l'utilisateur sans aucun appel serveur.
 * Les compétences cochées restent visibles même si elles ne correspondent pas à la recherche,
 * pour éviter qu'un pilote ne les décoche accidentellement en cherchant autre chose.
 */

document.getElementById('skills-search')?.addEventListener('input', function () {
    const query = this.value.toLowerCase().trim();
    const labels = document.querySelectorAll('#skills-list label');
    const emptyMsg = document.getElementById('skills-empty');
    let visibleCount = 0;

    labels.forEach(function (label) {
        const name = label.querySelector('span')?.textContent.toLowerCase() ?? '';
        const checkbox = label.querySelector('input[type="checkbox"]');
        const isChecked = checkbox?.checked ?? false;

        // Une compétence reste visible si elle correspond à la recherche OU si elle est cochée
        // (évite de masquer une compétence sélectionnée pendant la saisie)
        const visible = query === '' || name.includes(query) || isChecked;

        label.style.display = visible ? 'flex' : 'none';
        if (visible) visibleCount++;
    });

    // Affiche le message "Aucune compétence trouvée" uniquement si rien n'est visible
    if (emptyMsg) {
        emptyMsg.style.display = visibleCount === 0 ? 'block' : 'none';
    }
});
