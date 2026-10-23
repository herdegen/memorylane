<?php

/*
 * Famille fictive « Lambert » du site de démonstration (demo:build).
 * Tout est inventé. Le visiteur est Camille Lambert (compte « demo »).
 *
 * people   : clé => [prénom, nom, genre, naissance, lieu, décès, lieu, père, mère, nom de naissance]
 * couples  : [personne1, personne2, type (spouse|partner), date, lieu]
 * events   : [personne, type, titre, date (Y | Y-m | Y-m-d), lieu]
 * friends  : fiches du cercle d'amis (hors répertoire familial)
 */
return [
    'places' => [
        'Quimper' => [47.996, -4.102], 'Brest' => [48.390, -4.486], 'Lyon' => [45.764, 4.835],
        'Valence' => [44.933, 4.892], 'Nantes' => [47.218, -1.553], 'Rennes' => [48.117, -1.677],
        'Morlaix' => [48.577, -3.828], 'Grenoble' => [45.188, 5.724], 'Angers' => [47.478, -0.563],
        'Bordeaux' => [44.837, -0.579], 'Tours' => [47.394, 0.684], 'Paris' => [48.857, 2.352],
        'Lille' => [50.629, 3.057], 'Marseille' => [43.296, 5.370], 'Saint-Malo' => [48.649, -2.025],
        'Chamonix' => [45.924, 6.869], 'Villefranche-sur-Saône' => [45.990, 4.719], 'Amsterdam' => [52.373, 4.893],
        'Florence' => [43.769, 11.256], 'Santorin' => [36.393, 25.461], 'Chambord' => [47.616, 1.517],
        'Oxford' => [51.752, -1.258], 'Toulouse' => [43.605, 1.444],
    ],

    'people' => [
        // Génération 1 (vers 1890)
        'joseph' => ['Joseph', 'Lambert', 'M', '1892-03-14', 'Quimper', '1968-11-02', 'Brest', null, null, null],
        'marie' => ['Marie', 'Lambert', 'F', '1896-07-21', 'Quimper', '1979-01-15', 'Brest', null, null, 'Le Bihan'],
        'auguste' => ['Auguste', 'Moreau', 'M', '1895-10-02', 'Lyon', '1970-04-28', 'Lyon', null, null, null],
        'jeanne' => ['Jeanne', 'Moreau', 'F', '1899-05-09', 'Valence', '1985-08-30', 'Lyon', null, null, 'Fabre'],
        // Génération 2 (années 1920)
        'pierre' => ['Pierre', 'Lambert', 'M', '1922-05-03', 'Brest', '2004-09-18', 'Nantes', 'joseph', 'marie', null],
        'yvonne' => ['Yvonne', 'Guillou', 'F', '1926-02-17', 'Brest', '2012-03-06', 'Rennes', 'joseph', 'marie', 'Lambert'],
        'rene' => ['René', 'Guillou', 'M', '1924-12-01', 'Morlaix', '1999-06-21', 'Rennes', null, null, null],
        'odette' => ['Odette', 'Lambert', 'F', '1925-02-11', 'Lyon', '2016-12-03', 'Nantes', 'auguste', 'jeanne', 'Moreau'],
        'lucien' => ['Lucien', 'Moreau', 'M', '1928-08-19', 'Lyon', '2009-01-12', 'Grenoble', 'auguste', 'jeanne', null],
        // Génération 3 (années 1950)
        'jean' => ['Jean', 'Lambert', 'M', '1950-08-12', 'Nantes', null, null, 'pierre', 'odette', null],
        'francoise' => ['Françoise', 'Lambert', 'F', '1952-04-27', 'Angers', null, null, null, null, 'Dubois'],
        'michel' => ['Michel', 'Lambert', 'M', '1953-11-30', 'Nantes', null, null, 'pierre', 'odette', null],
        'catherine' => ['Catherine', 'Lambert', 'F', '1955-06-08', 'Bordeaux', null, null, null, null, 'Roux'],
        'annie' => ['Annie', 'Garnier', 'F', '1957-01-22', 'Nantes', null, null, 'pierre', 'odette', 'Lambert'],
        'patrick' => ['Patrick', 'Garnier', 'M', '1954-09-14', 'Tours', null, null, null, null, null],
        'herve' => ['Hervé', 'Guillou', 'M', '1951-03-03', 'Rennes', null, null, 'rene', 'yvonne', null],
        // Génération 4 (1975-1990)
        'sophie' => ['Sophie', 'Bernard', 'F', '1977-10-05', 'Nantes', null, null, 'jean', 'francoise', 'Lambert'],
        'thomas' => ['Thomas', 'Bernard', 'M', '1975-02-26', 'Paris', null, null, null, null, null],
        'nicolas' => ['Nicolas', 'Lambert', 'M', '1980-07-17', 'Nantes', null, null, 'jean', 'francoise', null],
        'julie' => ['Julie', 'Martin', 'F', '1983-12-09', 'Lille', null, null, null, null, null],
        'camille' => ['Camille', 'Lambert', 'F', '1990-06-14', 'Nantes', null, null, 'jean', 'francoise', null],
        'antoine' => ['Antoine', 'Lambert', 'M', '1982-04-04', 'Bordeaux', null, null, 'michel', 'catherine', null],
        'ines' => ['Inès', 'Lambert', 'F', '1984-08-23', 'Marseille', null, null, null, null, 'Benali'],
        'lea' => ['Léa', 'Garnier', 'F', '1985-05-30', 'Tours', null, null, 'patrick', 'annie', null],
        // Génération 5 (enfants)
        'lucas' => ['Lucas', 'Bernard', 'M', '2006-03-11', 'Nantes', null, null, 'thomas', 'sophie', null],
        'emma' => ['Emma', 'Bernard', 'F', '2009-09-02', 'Nantes', null, null, 'thomas', 'sophie', null],
        'hugo' => ['Hugo', 'Lambert', 'M', '2014-01-27', 'Lille', null, null, 'nicolas', 'julie', null],
        'chloe' => ['Chloé', 'Lambert', 'F', '2017-11-15', 'Lille', null, null, 'nicolas', 'julie', null],
        'jade' => ['Jade', 'Lambert', 'F', '2019-07-08', 'Bordeaux', null, null, 'antoine', 'ines', null],
    ],

    'couples' => [
        ['joseph', 'marie', 'spouse', '1919-06-14', 'Quimper'],
        ['auguste', 'jeanne', 'spouse', '1921-09-24', 'Lyon'],
        ['pierre', 'odette', 'spouse', '1948-05-22', 'Lyon'],
        ['rene', 'yvonne', 'spouse', '1949-07-02', 'Brest'],
        ['jean', 'francoise', 'spouse', '1975-09-13', 'Angers'],
        ['michel', 'catherine', 'spouse', '1979-06-30', 'Bordeaux'],
        ['patrick', 'annie', 'spouse', '1982-05-08', 'Nantes'],
        ['thomas', 'sophie', 'spouse', '2004-06-19', 'Chambord'],
        ['nicolas', 'julie', 'partner', '2011-04-01', 'Lille'],
        ['antoine', 'ines', 'spouse', '2016-09-10', 'Marseille'],
    ],

    'events' => [
        ['joseph', 'job', 'Marin pêcheur', '1910', 'Quimper'],
        ['joseph', 'residence', 'Maison du port', '1920', 'Brest'],
        ['joseph', 'job', 'Gardien de phare', '1932', 'Brest'],
        ['marie', 'job', 'Couturière', '1914', 'Quimper'],
        ['auguste', 'job', 'Soyeux, maître tisseur', '1919', 'Lyon'],
        ['auguste', 'residence', 'Pentes de la Croix-Rousse', '1921', 'Lyon'],
        ['jeanne', 'job', 'Institutrice', '1920', 'Valence'],
        ['pierre', 'education', 'École navale', '1940', 'Brest'],
        ['pierre', 'job', 'Ingénieur aux chantiers navals', '1949', 'Nantes'],
        ['pierre', 'residence', 'Maison de l’Erdre', '1950', 'Nantes'],
        ['odette', 'education', 'École des beaux-arts', '1943', 'Lyon'],
        ['odette', 'job', 'Professeure de dessin', '1950', 'Nantes'],
        ['lucien', 'job', 'Guide de haute montagne', '1952', 'Grenoble'],
        ['jean', 'education', 'Faculté de médecine', '1968', 'Nantes'],
        ['jean', 'job', 'Médecin de famille', '1977', 'Nantes'],
        ['jean', 'moment', 'Voyage de noces à Santorin', '1975-09', 'Santorin'],
        ['francoise', 'job', 'Pharmacienne', '1976', 'Nantes'],
        ['michel', 'job', 'Œnologue', '1978', 'Bordeaux'],
        ['sophie', 'education', 'École d’architecture', '1995', 'Nantes'],
        ['sophie', 'job', 'Architecte', '2001', 'Nantes'],
        ['nicolas', 'job', 'Chef cuisinier', '2005', 'Lille'],
        ['camille', 'education', 'Semestre Erasmus', '2011-01', 'Oxford'],
        ['camille', 'education', 'Master de journalisme', '2012', 'Toulouse'],
        ['camille', 'job', 'Journaliste', '2014', 'Paris'],
        ['camille', 'residence', 'Appartement du canal Saint-Martin', '2016', 'Paris'],
        ['antoine', 'job', 'Pilote de ligne', '2008', 'Bordeaux'],
        ['lucas', 'education', 'Lycée Guist’hau', '2021', 'Nantes'],
    ],

    'friends' => [
        'mathilde' => ['Mathilde', 'Rousseau', 'F', '1990-02-19', 'Toulouse'],
        'karim' => ['Karim', 'Haddad', 'M', '1989-11-03', 'Marseille'],
        'paul' => ['Paul', 'Lefèvre', 'M', '1991-05-27', 'Lille'],
    ],

    // Comptes : le visiteur (Camille) et quelques proches qui « ont envoyé » des photos.
    'accounts' => [
        'camille' => ['email' => 'demo@memory-lane.fr', 'name' => 'Camille Lambert'],
        'jean' => ['email' => 'jean.lambert@demo.memory-lane.fr', 'name' => 'Jean Lambert'],
        'sophie' => ['email' => 'sophie.bernard@demo.memory-lane.fr', 'name' => 'Sophie Bernard'],
        'mathilde' => ['email' => 'mathilde.rousseau@demo.memory-lane.fr', 'name' => 'Mathilde Rousseau'],
    ],
];
