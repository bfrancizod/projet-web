/**
 * Validation côté client du fichier CV avant soumission du formulaire de candidature.
 *
 * L'attribut HTML "accept" filtre le type de fichier affiché dans l'explorateur,
 * mais le navigateur ne peut pas vérifier la taille — seul JavaScript le peut.
 *
 * Note : la validation serveur (ApplyController) reste la référence absolue.
 * Ce script améliore uniquement l'expérience utilisateur en donnant un retour immédiat,
 * sans remplacer les contrôles côté serveur (type MIME réel, taille, quota 20 CVs).
 */

document.getElementById('cv')?.addEventListener('change', function () {
    const maxSize = 2 * 1024 * 1024; // 2 Mo en octets

    if (this.files[0] && this.files[0].size > maxSize) {
        // setCustomValidity + reportValidity affiche le message natif du navigateur
        // sous le champ, sans JS supplémentaire ni CSS personnalisé
        this.setCustomValidity('Le fichier ne doit pas dépasser 2 Mo.');
        this.reportValidity();
        this.value = ''; // réinitialise le champ pour forcer un nouveau choix
    } else {
        this.setCustomValidity(''); // efface le message d'erreur si le fichier est valide
    }
});
