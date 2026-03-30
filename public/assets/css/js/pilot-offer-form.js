document.addEventListener('DOMContentLoaded', function () {
    const config = window.offerFormSkills;

    if (!config) {
        console.error('offerFormSkills absent');
        return;
    }

    const existingSkills = Array.isArray(config.existingSkills) ? config.existingSkills : [];
    const selectedExistingSkillIds = new Set(
        Array.isArray(config.selectedExistingSkillIds) ? config.selectedExistingSkillIds.map(Number) : []
    );
    const selectedNewSkillNames = new Set(
        Array.isArray(config.selectedNewSkillNames) ? config.selectedNewSkillNames : []
    );

    const skillSearchInput = document.getElementById('skill_search');
    const newSkillInput = document.getElementById('new_skill_input');
    const addExistingSkillBtn = document.getElementById('add-existing-skill-btn');
    const addNewSkillBtn = document.getElementById('add-new-skill-btn');
    const selectedSkillsList = document.getElementById('selected-skills-list');
    const selectedSkillInputs = document.getElementById('selected-skill-inputs');

    if (
        !skillSearchInput ||
        !newSkillInput ||
        !addExistingSkillBtn ||
        !addNewSkillBtn ||
        !selectedSkillsList ||
        !selectedSkillInputs
    ) {
        console.error('Éléments du formulaire compétences introuvables');
        return;
    }

    function normalize(value) {
        return String(value).trim().toLowerCase();
    }

    function rebuildHiddenInputs() {
        selectedSkillInputs.innerHTML = '';

        selectedExistingSkillIds.forEach(function (skillId) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'competence_ids[]';
            input.value = String(skillId);
            selectedSkillInputs.appendChild(input);
        });

        selectedNewSkillNames.forEach(function (skillName) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'new_skill_names[]';
            input.value = skillName;
            selectedSkillInputs.appendChild(input);
        });
    }

    function createBadge(label, onRemove) {
        const item = document.createElement('div');
        item.className = 'selected-skill-badge';

        const text = document.createElement('span');
        text.textContent = label;

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'selected-skill-remove';
        removeBtn.textContent = 'Supprimer';
        removeBtn.addEventListener('click', onRemove);

        item.appendChild(text);
        item.appendChild(removeBtn);

        return item;
    }

    function renderSelectedSkills() {
        selectedSkillsList.innerHTML = '';

        existingSkills.forEach(function (skill) {
            if (selectedExistingSkillIds.has(Number(skill.id))) {
                selectedSkillsList.appendChild(
                    createBadge(skill.name, function () {
                        selectedExistingSkillIds.delete(Number(skill.id));
                        rebuildHiddenInputs();
                        renderSelectedSkills();
                    })
                );
            }
        });

        selectedNewSkillNames.forEach(function (skillName) {
            selectedSkillsList.appendChild(
                createBadge(skillName, function () {
                    selectedNewSkillNames.delete(skillName);
                    rebuildHiddenInputs();
                    renderSelectedSkills();
                })
            );
        });
    }

    addExistingSkillBtn.addEventListener('click', function () {
        const value = skillSearchInput.value.trim();
        if (!value) {
            return;
        }

        const foundSkill = existingSkills.find(function (skill) {
            return normalize(skill.name) === normalize(value);
        });

        if (!foundSkill) {
            alert('Cette compétence n’existe pas dans la liste.');
            return;
        }

        selectedExistingSkillIds.add(Number(foundSkill.id));

        Array.from(selectedNewSkillNames).forEach(function (skillName) {
            if (normalize(skillName) === normalize(foundSkill.name)) {
                selectedNewSkillNames.delete(skillName);
            }
        });

        skillSearchInput.value = '';
        rebuildHiddenInputs();
        renderSelectedSkills();
    });

    addNewSkillBtn.addEventListener('click', function () {
        const value = newSkillInput.value.trim();
        if (!value) {
            return;
        }

        const existingSkill = existingSkills.find(function (skill) {
            return normalize(skill.name) === normalize(value);
        });

        if (existingSkill) {
            selectedExistingSkillIds.add(Number(existingSkill.id));
        } else {
            let duplicate = false;

            selectedNewSkillNames.forEach(function (skillName) {
                if (normalize(skillName) === normalize(value)) {
                    duplicate = true;
                }
            });

            if (!duplicate) {
                selectedNewSkillNames.add(value);
            }
        }

        newSkillInput.value = '';
        rebuildHiddenInputs();
        renderSelectedSkills();
    });

    rebuildHiddenInputs();
    renderSelectedSkills();
});