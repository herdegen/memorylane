/**
 * Libellé d'affichage d'une personne : « Prénom Nom (Nom de naissance) ».
 * Le nom de naissance (maiden_name) n'est ajouté que s'il est renseigné et
 * distinct du nom d'usage. Accepte aussi bien un objet Person (page Personnes)
 * qu'un `data` de nœud d'arbre (mêmes champs name / last_name / maiden_name).
 */
export function personLabel(p) {
  if (!p) return '';

  const display = (p.name || [p.first_name, p.last_name].filter(Boolean).join(' ')).trim();
  const maiden = (p.maiden_name || '').trim();

  if (maiden && maiden !== (p.last_name || '') && !display.includes(maiden)) {
    return `${display} (${maiden})`;
  }

  return display;
}
