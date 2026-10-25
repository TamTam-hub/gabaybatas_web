<?php
/**
 * GabayBatas — Legal Information content (resident app).
 *
 * Loaded into the `legal_information` table by tools/seed_legal_information.php.
 * Each article's `content` uses "## Section" headings; lines starting with "- "
 * are bullets and lines starting with "1. " are numbered steps. `reference`
 * holds one source per line.
 *
 * This is general legal information, not legal advice. Exact peso thresholds
 * that depend on RA 10951 are intentionally not stated; the barangay checks
 * coverage when it reviews a complaint.
 */

const KP_REF_LGC        = 'Republic Act No. 7160 (Local Government Code of 1991), Book III, Title One, Chapter 7, Secs. 399–422 (Katarungang Pambarangay)';
const KP_REF_LGC_408    = 'RA 7160, Sec. 408 — Subject matter for amicable settlement; exceptions';
const KP_REF_SC_1493    = 'Supreme Court Administrative Circular No. 14-93 (July 15, 1993) — Guidelines on the Katarungang Pambarangay conciliation procedure';
const KP_REF_HB_2004    = 'Katarungang Pambarangay: A Handbook (Philippines-Canada Local Government Support Program and SALIGAN, 2004)';
const KP_REF_HB_2022    = 'Katarungang Pambarangay — Tagalog Version (Sentro ng Alternatibong Lingap Panligal / SALIGAN, 2022)';
const KP_REF_RPC        = 'Act No. 3815 (Revised Penal Code), as amended';
const KP_REF_RA10951    = 'Republic Act No. 10951 (2017) — adjusts the property values and fines on which penalties under the Revised Penal Code are based';

return [

    // ─────────────────────────── GUIDES ───────────────────────────

    [
        'title'         => 'Where Should I Report?',
        'category'      => 'Guide',
        'summary'       => 'Which office handles your concern: the barangay, the police, the VAW Desk, DOLE, DAR or the court.',
        'offense_class' => '',
        'report_to'     => 'Depends on the concern',
        'case_type'     => '',
        'content'       => <<<'TXT'
## Start with the barangay for most disputes
Disputes between people who live in the same city or municipality — debts, noise, boundaries, minor injuries, slander, threats and similar problems — must first go through the Lupong Tagapamayapa of the barangay before they can be filed in court. This is called the Katarungang Pambarangay (KP). If the case skips the barangay, the court can dismiss it.

## Which barangay?
- File at the barangay where the person you are complaining about lives. If there are several of them, you may choose the barangay of any one of them.
- A dispute about land is filed at the barangay where the land is located.
- A dispute that arose at a workplace or school is filed at the barangay where that workplace or school is.

## Go straight to the police (or call 911) when
- Someone is in immediate danger or a crime is happening now.
- The crime is serious: serious physical injuries, robbery, theft of high value, illegal drugs, rape, killing, or any offense punishable by more than 1 year in prison or a fine of more than ₱5,000.
- The suspect has been arrested and is detained.

## Violence against women and children
- Violence or abuse against a woman or her child (RA 9262) cannot go through barangay mediation. Go to the Barangay VAW Desk, which can issue a Barangay Protection Order, or to the PNP Women and Children Protection Desk.
- Abuse of a child (RA 7610) should be reported to the PNP Women and Children Protection Desk or the city social welfare office.

## Other offices
- Work problems between an employer and an employee: Department of Labor and Employment (DOLE).
- Farmland and agrarian reform disputes: Department of Agrarian Reform (DAR).
- Complaints against a business about products or services: Department of Trade and Industry (DTI).
- Online threats, scams or defamation: PNP Anti-Cybercrime Group or NBI Cybercrime Division.
- Disputes with a company, the government, or a public officer about official work: the proper agency or the court (these are not covered by the barangay).

## Not sure?
Use the "Where should I report?" guide in this app, or go to the Barangay Hall. Barangay officials can tell you if your complaint is covered and where to bring it if it is not.
TXT,
        'reference'     => implode("\n", [
            KP_REF_LGC_408,
            'RA 7160, Sec. 409 — Venue (barangay of the respondent; land; workplace or school)',
            'RA 7160, Sec. 412 — Conciliation is a pre-condition to filing a complaint in court',
            KP_REF_SC_1493,
            'Republic Act No. 9262 (Anti-Violence Against Women and Their Children Act of 2004), Sec. 14 — Barangay Protection Orders',
            'Republic Act No. 7610 (Special Protection of Children Against Abuse, Exploitation and Discrimination Act)',
            'Republic Act No. 10175 (Cybercrime Prevention Act of 2012)',
            KP_REF_HB_2022 . ', pp. 24–28 (Hurisdiksiyon o Saklaw ng Lupon)',
        ]),
    ],

    [
        'title'         => 'Classes of Crimes and Penalties',
        'category'      => 'Guide',
        'summary'       => 'Grave, less grave and light felonies, the penalty scale, and which ones go to the barangay first.',
        'offense_class' => '',
        'report_to'     => 'Depends on the penalty',
        'case_type'     => '',
        'content'       => <<<'TXT'
## Three classes of felonies
- Grave felonies are punished with afflictive penalties (prisión mayor and higher).
- Less grave felonies are punished with correctional penalties (prisión correccional, arresto mayor, suspension, destierro).
- Light felonies are punished with arresto menor or a fine of not more than ₱40,000, or both.

## The penalty scale
- Arresto menor: 1 day to 30 days
- Arresto mayor: 1 month and 1 day to 6 months
- Prisión correccional: 6 months and 1 day to 6 years
- Prisión mayor: 6 years and 1 day to 12 years
- Reclusión temporal: 12 years and 1 day to 20 years
- Reclusión perpetua: 20 years and 1 day to 40 years

## Classes of fines
- Light fine: less than ₱40,000
- Correctional fine: ₱40,000 to ₱1,200,000
- Afflictive fine: more than ₱1,200,000

## What this means for where you report
- Offenses punishable by imprisonment of not more than 1 year or a fine of not more than ₱5,000 go through the barangay first.
- In practice, light felonies and less grave felonies punished with arresto mayor (up to 6 months) usually start at the barangay.
- Offenses punished with prisión correccional or higher, or a fine above ₱5,000, go to the police or the prosecutor.
- RA 10951 raised the fines of some offenses above ₱5,000. A clarification from the Department of Justice on its effect on barangay coverage has been requested, so the barangay checks each complaint.

## Examples
- Usually at the barangay: slight physical injuries, less serious physical injuries, light threats, unjust vexation, slight oral defamation, other forms of trespass, low-value malicious mischief.
- Usually with the police or prosecutor: serious physical injuries, robbery, qualified theft of high value, grave threats with a demand, rape, homicide.
TXT,
        'reference'     => implode("\n", [
            KP_REF_RPC . ', Art. 9 — Grave, less grave and light felonies (as amended by RA 10951)',
            KP_REF_RPC . ', Art. 25 — Penalties which may be imposed',
            KP_REF_RPC . ', Art. 26 — Classification of fines (as amended by RA 10951)',
            KP_REF_RPC . ', Art. 27 — Duration of penalties (as amended by RA 7659)',
            KP_REF_RA10951,
            KP_REF_LGC_408 . ' (offenses punishable by more than 1 year or a fine of more than ₱5,000 are excluded)',
            KP_REF_HB_2022 . ', p. 26 (note on RA 10951)',
        ]),
    ],

    [
        'title'         => 'The Barangay Process Step by Step',
        'category'      => 'Guide',
        'summary'       => 'What happens after you file a complaint: mediation, the Pangkat, settlement, and what to expect.',
        'offense_class' => '',
        'report_to'     => 'Barangay (Lupong Tagapamayapa)',
        'case_type'     => '',
        'content'       => <<<'TXT'
## 1. Filing the complaint
1. File your complaint at the barangay, in person or through this app, and pay the filing fee.
2. The complaint is recorded on KP Form No. 7 and given a barangay case number.
3. The respondent is summoned on the next working day, and you receive a notice of hearing (KP Forms No. 8 and 9).

## 2. Mediation before the Punong Barangay
1. You and the respondent meet before the Punong Barangay, who helps you agree on a solution.
2. Mediation lasts up to 15 days.
3. If you agree, your amicable settlement is written down and signed (KP Form No. 16).

## 3. Conciliation before the Pangkat
1. If mediation fails, a Pangkat ng Tagapagkasundo of 3 Lupon members is formed within 15 days (KP Form No. 10).
2. The Pangkat meets within 3 days and has 15 days to help you settle, extendable by 15 days.
3. If you settle, the agreement is signed. If not, a Certification to File Action is issued so you can go to court (KP Form No. 20-A).

## 4. Arbitration (only if both agree)
At any stage, both parties may agree in writing to let the Punong Barangay (during mediation) or the Pangkat (during conciliation) decide the case (KP Form No. 14). Either party may withdraw within 5 days. The award is made within 10 days after that period.

## 5. After a settlement or award
- A settlement can be repudiated within 10 days if consent was obtained by fraud, violence or intimidation (KP Form No. 17).
- After 10 days it has the force of a final court judgment.
- If the other party does not comply, you may ask the barangay to enforce it within 6 months (KP Forms No. 23 to 25). After that, enforcement is through the court.

## If someone does not appear
- If you, the complainant, do not appear without a valid reason, your complaint may be dismissed and you may be barred from filing it in court.
- If the respondent does not appear without a valid reason, the complainant may be allowed to go to court and the respondent may lose the right to file a counterclaim.
TXT,
        'reference'     => implode("\n", [
            'RA 7160, Sec. 410 — Procedure for amicable settlement',
            'RA 7160, Sec. 411 — Form of settlement',
            'RA 7160, Sec. 413 — Arbitration',
            'RA 7160, Secs. 416–418 — Effect, execution and repudiation of settlement',
            'RA 7160, Sec. 419 — Transmittal of settlement and arbitration award to the court',
            KP_REF_HB_2022 . ', pp. 31–38 (Proseso ng Katarungang Pambarangay)',
            KP_REF_HB_2004 . ', Part 1, Chapters 2–4',
            'Department of the Interior and Local Government, KP Forms (2025 downloadable forms)',
        ]),
    ],

    [
        'title'         => 'Your Rights at the Barangay',
        'category'      => 'Guide',
        'summary'       => 'No lawyer needed, appearing in person, speaking your own language, and repudiating an unfair settlement.',
        'offense_class' => '',
        'report_to'     => 'Barangay (Lupong Tagapamayapa)',
        'case_type'     => '',
        'content'       => <<<'TXT'
## You do not need a lawyer
Parties must appear in person. Lawyers are not allowed to represent parties in barangay proceedings. A minor or a person who cannot act for himself or herself may be assisted by a relative or guardian who is not a lawyer.

## You may use the language you know
Settlements and awards must be written in a language or dialect known to the parties.

## The process is free and fast
Apart from the filing fee, there are no lawyer's fees. Each stage has a time limit: 15 days for mediation and 15 days (extendable by 15) for conciliation.

## You may reject an unfair settlement
If you agreed only because you were deceived, threatened or forced, you may repudiate the settlement within 10 days by a sworn statement before the Punong Barangay.

## Your time to file in court is protected
While your complaint is pending at the barangay, the time limit (prescriptive period) for filing your case in court is interrupted, for up to 60 days.

## Venue
Complaints are filed in the barangay where the respondent lives. Disputes about land go to the barangay where the land is, and disputes at work or school go to the barangay where the workplace or school is. Objections to the venue must be raised during mediation.
TXT,
        'reference'     => implode("\n", [
            'RA 7160, Sec. 415 — Appearance of parties in person; lawyers not allowed',
            'RA 7160, Sec. 411 — Form of settlement (written in a language or dialect known to the parties)',
            'RA 7160, Sec. 418 — Repudiation within 10 days',
            'RA 7160, Sec. 410(c) — Suspension of prescriptive periods',
            'RA 7160, Sec. 409 — Venue',
            KP_REF_HB_2022 . ', pp. 22–23 and 29–30',
        ]),
    ],

    [
        'title'         => 'Complaints the Barangay Cannot Handle',
        'category'      => 'Guide',
        'summary'       => 'Cases that do not go through barangay mediation, and where to bring them instead.',
        'offense_class' => '',
        'report_to'     => 'Proper agency or court',
        'case_type'     => '',
        'content'       => <<<'TXT'
## Not covered by the Katarungang Pambarangay
- Violence against women and their children (RA 9262). Barangay officials are prohibited from mediating these cases.
- Cases where one party is the government or any of its agencies or offices.
- Cases where one party is a public officer or employee and the dispute is about his or her official functions.
- Offenses punishable by imprisonment of more than 1 year or a fine of more than ₱5,000.
- Offenses where there is no private offended party.
- Disputes involving land in different cities or municipalities, unless the parties agree to submit them.
- Disputes between people living in different cities or municipalities, unless their barangays are adjoining and they agree to submit them.
- Cases with a corporation, partnership, estate or other juridical person as a party.
- Labor disputes between an employer and an employee, and agrarian disputes.
- Actions to annul a judgment based on a compromise.

## You may go directly to court when
- The accused is under detention.
- A person is deprived of liberty and needs a petition for habeas corpus.
- The case asks for urgent court remedies such as a preliminary injunction, attachment, delivery of personal property, or support while the case is pending.
- The case may prescribe (the time limit may lapse) if it goes through the barangay first.

## What the barangay does
If your complaint is not covered, the barangay records why and tells you where to bring it.
TXT,
        'reference'     => implode("\n", [
            KP_REF_LGC_408,
            'RA 7160, Sec. 412(b) — Where parties may go directly to court',
            KP_REF_SC_1493,
            'Republic Act No. 9262, Sec. 33 — Prohibited acts (barangay officials may not mediate VAWC cases)',
            KP_REF_HB_2022 . ', pp. 25–28',
        ]),
    ],

    [
        'title'         => 'Getting Free Legal Help',
        'category'      => 'Guide',
        'summary'       => 'Where to get free legal advice if you need more than the barangay can give.',
        'offense_class' => '',
        'report_to'     => 'Public Attorney\'s Office (PAO)',
        'case_type'     => '',
        'content'       => <<<'TXT'
## Public Attorney's Office (PAO)
The PAO gives free legal advice and representation to people who cannot afford a lawyer, in criminal, civil, labor and administrative cases. Bring a valid ID and proof of income (such as a certificate of indigency from your barangay).

## Integrated Bar of the Philippines (IBP)
Local IBP chapters run free legal aid programs for qualified clients.

## Remember
- Barangay proceedings do not need a lawyer.
- This app gives general legal information, not legal advice. For advice on your specific situation, talk to the PAO or a lawyer.
TXT,
        'reference'     => implode("\n", [
            'Republic Act No. 9406 (2007) — Reorganizing and strengthening the Public Attorney\'s Office',
            'RA 7160, Sec. 415 — Lawyers not allowed in barangay proceedings',
        ]),
    ],

    // ─────────────────────────── CASE TYPES ───────────────────────────

    [
        'title'         => 'Public Disturbance',
        'category'      => 'Criminal',
        'summary'       => 'Acts that disturb public peace and order, such as scandalous behavior or disorderly fights.',
        'offense_class' => 'Usually a light felony',
        'report_to'     => 'Barangay first · Police if violent',
        'case_type'     => 'Public Disturbance',
        'content'       => <<<'TXT'
## What it is
Acts that disturb public peace and order or cause alarm or scandal in the community, such as drunken or disorderly behavior in public, starting a commotion, or scandalous conduct.

## Examples
- A neighbor shouting and causing a scene in the street late at night
- A drunken person picking fights at a public place
- Firing a gun or lighting firecrackers that alarm the neighborhood

## Where to report
- Barangay first, if the other person lives in the same city and no one is in danger.
- Call 911 or the PNP if there is violence, a weapon, or danger.

## What to bring
- Your valid ID
- Date, time and place of the incident
- Names of the persons involved and of witnesses
- Photos or videos, if any

## What happens next
1. The barangay records your complaint and summons the respondent.
2. You meet before the Punong Barangay for mediation.
3. If you settle, the agreement is signed. If not, the case moves to the Pangkat.

## Your rights
- You may file without a lawyer.
- Violations of barangay or city ordinances (curfew, noise, liquor rules) may also be acted on by barangay officials.
TXT,
        'reference'     => implode("\n", [
            KP_REF_RPC . ', Art. 153 — Tumults and other disturbances of public order',
            KP_REF_RPC . ', Art. 155 — Alarms and scandals',
            KP_REF_LGC_408,
            KP_REF_HB_2004 . ', p. 22 (cases under KP)',
        ]),
    ],

    [
        'title'         => 'Theft',
        'category'      => 'Criminal',
        'summary'       => 'Taking someone\'s personal property without consent and with intent to gain, without violence.',
        'offense_class' => 'Light to grave, depending on value',
        'report_to'     => 'Barangay if low value · Police if high value',
        'case_type'     => 'Theft',
        'content'       => <<<'TXT'
## What it is
Theft is taking personal property belonging to another, with intent to gain and without the owner's consent, but without violence, intimidation or force on things. If violence, intimidation or force is used, it becomes robbery, which is more serious.

## Examples
- A neighbor taking your chickens, tools or laundry
- Someone keeping a cellphone they found and know belongs to you

## Where to report
- Barangay first, if the value of the property is low and the penalty is not more than 1 year. The barangay checks the value when it reviews your complaint.
- Police (PNP), if the value is high, if it is robbery, or if the thief is a household helper or someone you trusted (qualified theft).

## What to bring
- Your valid ID
- Proof that you own the item: receipts, photos, serial numbers
- Names of witnesses
- CCTV footage or messages, if any

## What happens next
1. The barangay summons the respondent for mediation.
2. You may agree on the return of the item or payment of its value.
3. If there is no settlement, a Certification to File Action lets you file the case with the prosecutor.

## Important
The penalty for theft depends on the value of the property taken, as adjusted by RA 10951.
TXT,
        'reference'     => implode("\n", [
            KP_REF_RPC . ', Art. 308 — Who are liable for theft',
            KP_REF_RPC . ', Art. 309 — Penalties (as amended by RA 10951)',
            KP_REF_RPC . ', Art. 310 — Qualified theft',
            KP_REF_RPC . ', Art. 293 — Robbery (when violence, intimidation or force is used)',
            KP_REF_RA10951,
            KP_REF_LGC_408,
        ]),
    ],

    [
        'title'         => 'Physical Injury',
        'category'      => 'Criminal',
        'summary'       => 'Hurting another person. Slight and less serious injuries go to the barangay; serious injuries go to the police.',
        'offense_class' => 'Light or less grave (slight / less serious)',
        'report_to'     => 'Barangay (slight or less serious) · Police (serious)',
        'case_type'     => 'Physical Injury',
        'content'       => <<<'TXT'
## What it is
Physical injuries are classified by how serious they are:
- Slight physical injuries: the victim cannot work or needs medical attention for 1 to 9 days, or the injury needs no treatment, or it is maltreatment without injury.
- Less serious physical injuries: the victim cannot work or needs medical attention for 10 days or more (up to 30 days).
- Serious physical injuries: for example, loss of an eye or limb, permanent disability, or inability to work for more than 30 days.

## Where to report
- Slight and less serious physical injuries: barangay first.
- Serious physical injuries, or injuries with a weapon: police (PNP) and the prosecutor.
- Call 911 if the victim needs emergency care.

## What to bring
- A medical certificate or medico-legal report stating how many days of healing or incapacity
- Photos of the injuries
- Names of witnesses

## What happens next
1. The barangay summons the respondent for mediation.
2. Settlements often include payment of medical expenses.
3. If there is no settlement, a Certification to File Action is issued.

## Important
Get a medical certificate as soon as possible. The number of days stated on it decides the class of the injury.
TXT,
        'reference'     => implode("\n", [
            KP_REF_RPC . ', Art. 263 — Serious physical injuries',
            KP_REF_RPC . ', Art. 265 — Less serious physical injuries',
            KP_REF_RPC . ', Art. 266 — Slight physical injuries and maltreatment',
            KP_REF_LGC_408,
            KP_REF_HB_2004 . ', p. 22 (less serious and slight physical injuries are under KP)',
        ]),
    ],

    [
        'title'         => 'Property Damage',
        'category'      => 'Criminal',
        'summary'       => 'Deliberately damaging another person\'s property (malicious mischief), or causing damage through negligence.',
        'offense_class' => 'Criminal (malicious mischief) or civil (damages)',
        'report_to'     => 'Barangay first',
        'case_type'     => 'Property Damage',
        'content'       => <<<'TXT'
## What it is
- Malicious mischief: deliberately damaging another person's property out of hate, revenge or other evil motive.
- Damage through negligence: when someone's fault or carelessness damages your property, you may claim payment for the damage (a civil claim).

## Examples
- A neighbor destroying your fence or plants
- Someone scratching or breaking your vehicle
- A neighbor's construction damaging your wall

## Where to report
- Barangay first, if the other person lives in the same city. The barangay checks the value of the damage to confirm it is covered.
- Police, if the damage is very large or part of a more serious crime.

## What to bring
- Photos or videos of the damage
- Receipts or estimates of repair costs
- Names of witnesses

## What happens next
1. Mediation before the Punong Barangay.
2. Settlements usually involve repair or payment for the damage.
3. If there is no settlement, a Certification to File Action is issued.
TXT,
        'reference'     => implode("\n", [
            KP_REF_RPC . ', Art. 327 — Who are liable for malicious mischief',
            KP_REF_RPC . ', Arts. 328–329 — Special cases and other mischiefs (as amended by RA 10951)',
            'Republic Act No. 386 (Civil Code of the Philippines), Art. 2176 — Quasi-delicts (damage caused by fault or negligence)',
            KP_REF_RA10951,
            KP_REF_LGC_408,
        ]),
    ],

    [
        'title'         => 'Family Dispute (No Violence)',
        'category'      => 'Family',
        'summary'       => 'Disagreements between family members, without violence. Violence against women or children is not handled here.',
        'offense_class' => 'Civil',
        'report_to'     => 'Barangay first · VAW Desk if there is violence',
        'case_type'     => 'Family Dispute (No Violence)',
        'content'       => <<<'TXT'
## What it is
Disagreements between members of the same family — spouses, parents and children, siblings and other relatives — about money, property, support, or household matters, where there is no violence or abuse.

## Important: violence against women and children
If the dispute involves physical, sexual, psychological or economic abuse of a woman or her child, it is a VAWC case under RA 9262. Barangay officials are not allowed to mediate it. Go to the Barangay VAW Desk, which can issue a Barangay Protection Order, or the PNP Women and Children Protection Desk.

## Where to report
- Barangay (Lupong Tagapamayapa), for disputes without violence.
- Barangay VAW Desk or PNP Women and Children Protection Desk, if there is violence or abuse.

## What to bring
- Your valid ID
- A short account of the problem and any documents (e.g., titles, receipts, messages)

## What happens next
1. The barangay helps the family talk and agree through mediation.
2. The law requires earnest efforts to settle disputes between family members before going to court, and the barangay process helps meet this requirement.
TXT,
        'reference'     => implode("\n", [
            'Executive Order No. 209 (Family Code of the Philippines), Art. 150 — Family relations',
            'Family Code, Art. 151 — Earnest efforts toward a compromise before suits between family members',
            'Republic Act No. 9262 (Anti-VAWC Act of 2004), Secs. 14 and 33',
            KP_REF_LGC_408,
            KP_REF_HB_2022 . ', p. 26 (VAWC excluded from KP)',
        ]),
    ],

    [
        'title'         => 'Threat or Harassment',
        'category'      => 'Criminal',
        'summary'       => 'Threatening to harm someone, or annoying, coercing or harassing them.',
        'offense_class' => 'Light or less grave (most cases)',
        'report_to'     => 'Barangay first · Police for grave threats',
        'case_type'     => 'Threat or Harassment',
        'content'       => <<<'TXT'
## What it is
- Threats: telling someone you will harm them, their family or their property.
- Coercion: forcing someone, without legal authority, to do something against their will, or preventing them from doing something lawful.
- Unjust vexation: acts that annoy, irritate or disturb another person without a lawful reason.

## Where to report
- Barangay first, for light threats, other light threats, light coercion and unjust vexation.
- Police (PNP), for grave threats with a demand or condition, threats with a weapon, or when you feel in danger.
- Gender-based harassment in streets, workplaces or online is punished under the Safe Spaces Act (RA 11313); report it to the barangay VAW Desk or the PNP.
- Threats sent online may also be reported to the PNP Anti-Cybercrime Group.

## What to bring
- Screenshots of messages or posts, with dates
- Names of witnesses
- A written account of what was said and when

## What happens next
1. Mediation before the Punong Barangay.
2. Settlements may include a promise to stop and to keep away.
3. If there is no settlement, a Certification to File Action is issued.
TXT,
        'reference'     => implode("\n", [
            KP_REF_RPC . ', Art. 282 — Grave threats',
            KP_REF_RPC . ', Art. 283 — Light threats',
            KP_REF_RPC . ', Art. 285 — Other light threats',
            KP_REF_RPC . ', Art. 286 — Grave coercions',
            KP_REF_RPC . ', Art. 287 — Light coercions and unjust vexation',
            'Republic Act No. 11313 (Safe Spaces Act of 2019)',
            'Republic Act No. 10175 (Cybercrime Prevention Act of 2012)',
            KP_REF_HB_2004 . ', p. 22 (light threats, grave and light coercion listed under KP)',
        ]),
    ],

    [
        'title'         => 'Noise Complaint',
        'category'      => 'Civil',
        'summary'       => 'Loud and continuous noise that disturbs neighbors, such as videoke, parties or machines at night.',
        'offense_class' => 'Civil (nuisance) or ordinance violation',
        'report_to'     => 'Barangay first',
        'case_type'     => 'Noise Complaint',
        'content'       => <<<'TXT'
## What it is
Excessive noise that disturbs the comfort and peace of neighbors may be a nuisance under the Civil Code and may also violate barangay or city ordinances.

## Examples
- Loud videoke late at night
- Barking dogs that are not attended to
- Machines or construction at unreasonable hours

## Where to report
- Barangay first. Barangay officials can enforce local noise and curfew ordinances and help neighbors agree on rules.

## What to bring
- Dates and times when the noise happens
- Audio or video recordings
- Names of neighbors who are also affected

## What happens next
1. Mediation before the Punong Barangay.
2. Settlements often set quiet hours or other agreed rules.
3. Repeated violations of an agreement may be enforced through the barangay.
TXT,
        'reference'     => implode("\n", [
            'Civil Code of the Philippines, Art. 694 — Definition of nuisance',
            'Civil Code, Art. 695 — Public and private nuisance',
            'Civil Code, Art. 699 — Remedies against a public nuisance',
            KP_REF_RPC . ', Art. 155 — Alarms and scandals (disturbing the public peace)',
            KP_REF_LGC_408,
        ]),
    ],

    [
        'title'         => 'Boundary Dispute',
        'category'      => 'Civil',
        'summary'       => 'Disagreements about the boundary, fence or ownership of neighboring land.',
        'offense_class' => 'Civil',
        'report_to'     => 'Barangay where the land is',
        'case_type'     => 'Boundary Dispute',
        'content'       => <<<'TXT'
## What it is
A disagreement between neighbors about where one property ends and another begins, about a fence, or about the use of part of a lot.

## Where to report
- The barangay where the land is located.
- If the land is in a different city or municipality, the barangay cannot handle it unless both parties agree.
- Farmland covered by agrarian reform: Department of Agrarian Reform (DAR).

## What to bring
- Land title, tax declaration or deed of sale
- Survey plan or a relocation survey by a licensed geodetic engineer
- Photos of the boundary and fence

## What happens next
1. Mediation before the Punong Barangay.
2. Parties often agree to a relocation survey and to abide by its result.
3. If there is no settlement, a Certification to File Action lets you file a case in court.
TXT,
        'reference'     => implode("\n", [
            'Civil Code of the Philippines, Art. 428 — Rights of the owner',
            'Civil Code, Art. 430 — Right to enclose or fence one\'s land',
            'Civil Code, Art. 434 — In an action to recover, the property must be identified',
            'RA 7160, Sec. 409(c) — Venue for disputes involving real property',
            KP_REF_LGC_408,
            KP_REF_HB_2022 . ', p. 30 (venue)',
        ]),
    ],

    [
        'title'         => 'Unpaid Debt or Money Claim',
        'category'      => 'Civil',
        'summary'       => 'Collecting money that was lent or is owed, such as loans, unpaid goods or services.',
        'offense_class' => 'Civil',
        'report_to'     => 'Barangay first',
        'case_type'     => 'Unpaid Debt or Money Claim',
        'content'       => <<<'TXT'
## What it is
A claim to collect money that someone owes you — a personal loan (utang), unpaid goods bought on credit, or unpaid services. A person who borrows money must pay back the same amount, and agreements have the force of law between the parties.

## Important
No one may be imprisoned for failing to pay a debt. The remedy is to collect the money, not to file a criminal case — unless there was fraud (see Swindling or Estafa).

## Where to report
- Barangay first, if the other person lives in the same city.

## What to bring
- Promissory note, receipts, or written acknowledgment of the debt
- Text messages or chats about the loan
- Names of witnesses

## What happens next
1. Mediation before the Punong Barangay.
2. Settlements often set a payment schedule.
3. If the debtor does not follow the settlement, you may ask the barangay to enforce it within 6 months. Alternatively, you may treat the settlement as rescinded and pursue your original claim in court.
4. If there is no settlement, a Certification to File Action lets you file a case in court, such as a small claims case.
TXT,
        'reference'     => implode("\n", [
            'Civil Code of the Philippines, Art. 1156 — Definition of obligation',
            'Civil Code, Art. 1159 — Obligations arising from contracts have the force of law',
            'Civil Code, Art. 1953 — Obligation to pay a loan of money',
            '1987 Constitution, Art. III, Sec. 20 — No person shall be imprisoned for debt',
            'Supreme Court, Rules of Procedure for Small Claims Cases (A.M. No. 08-8-7-SC), as amended',
            'Miguel v. Montanez, G.R. No. 191336, January 25, 2012 (non-compliance with a barangay settlement)',
            KP_REF_HB_2022 . ', p. 39',
        ]),
    ],

    [
        'title'         => 'Oral Defamation (Slight)',
        'category'      => 'Criminal',
        'summary'       => 'Insulting or shaming someone in words, said in front of others. Slight cases go to the barangay.',
        'offense_class' => 'Light (slight) or less grave (grave)',
        'report_to'     => 'Barangay first (slight) · Prosecutor (grave)',
        'case_type'     => 'Oral Defamation (Slight)',
        'content'       => <<<'TXT'
## What it is
Oral defamation (slander) is saying something in front of others that dishonors, discredits or shames another person. It is slight or grave depending on the words used, the circumstances, and the relationship of the parties. Acts that shame someone (slander by deed) and spreading rumors to damage someone's reputation (intriguing against honor) are related offenses.

## Where to report
- Slight oral defamation: barangay first. The Supreme Court has held that a slight oral defamation case cannot proceed in court without first going through barangay conciliation.
- Grave oral defamation: police or prosecutor.
- Defamatory posts online may be cyber libel; report them to the PNP Anti-Cybercrime Group or the NBI.

## What to bring
- The exact words said, when and where
- Names of people who heard it
- Recordings or screenshots, if any

## What happens next
1. Mediation before the Punong Barangay.
2. Settlements often include a public or written apology.
3. If there is no settlement, a Certification to File Action is issued.
TXT,
        'reference'     => implode("\n", [
            KP_REF_RPC . ', Art. 353 — Definition of libel (defamation)',
            KP_REF_RPC . ', Art. 358 — Slander (oral defamation)',
            KP_REF_RPC . ', Art. 359 — Slander by deed',
            KP_REF_RPC . ', Art. 364 — Intriguing against honor',
            'Agbayani v. Court of Appeals, G.R. No. 183623, June 25, 2012',
            'Republic Act No. 10175, Sec. 4(c)(4) — Cyber libel',
            KP_REF_HB_2022 . ', p. 28',
        ]),
    ],

    [
        'title'         => 'Trespassing',
        'category'      => 'Criminal',
        'summary'       => 'Entering someone\'s house or closed property without permission.',
        'offense_class' => 'Less grave or light (without violence)',
        'report_to'     => 'Barangay first · Police if with violence',
        'case_type'     => 'Trespassing',
        'content'       => <<<'TXT'
## What it is
- Qualified trespass to dwelling: entering another person's house against the owner's will.
- Other forms of trespass: entering closed premises or a fenced estate without permission when it is uninhabited and the prohibition to enter is clear.

## Where to report
- Barangay first, if there was no violence or intimidation.
- Police (PNP), if violence, intimidation or a weapon was used, or if you feel in danger.

## What to bring
- Date, time and description of the entry
- CCTV footage, photos or videos
- Names of witnesses

## What happens next
1. Mediation before the Punong Barangay.
2. Settlements often include a promise not to enter again.
3. If there is no settlement, a Certification to File Action is issued.
TXT,
        'reference'     => implode("\n", [
            KP_REF_RPC . ', Art. 280 — Qualified trespass to dwelling',
            KP_REF_RPC . ', Art. 281 — Other forms of trespass',
            KP_REF_LGC_408,
            KP_REF_HB_2004 . ', p. 22 (qualified trespass without violence and other forms of trespass are under KP)',
        ]),
    ],

    [
        'title'         => 'Rental Dispute',
        'category'      => 'Civil',
        'summary'       => 'Disputes between a landlord and a tenant over a house or room: unpaid rent, repairs, deposits or eviction.',
        'offense_class' => 'Civil',
        'report_to'     => 'Barangay first',
        'case_type'     => 'Rental Dispute',
        'content'       => <<<'TXT'
## What it is
Disagreements under a lease of a house, apartment or room, such as unpaid rent, repairs, the return of a deposit or advance, rent increases, or ending the lease. Disputes over farmland are agrarian and go to the DAR instead.

## Where to report
- Barangay first, if the landlord and tenant live in the same city. A court case for ejectment requires prior barangay conciliation.

## What to bring
- The lease contract, if any
- Receipts of rent and deposit payments
- Messages or notices exchanged
- Photos of the unit, for repair or damage issues

## What happens next
1. Mediation before the Punong Barangay.
2. Settlements may set a payment schedule, a move-out date, or agreed repairs.
3. If there is no settlement, a Certification to File Action lets you file a case in court, such as ejectment.

## Your rights
The Rent Control Act limits rent increases for covered residential units and lists the grounds for ejecting a tenant.
TXT,
        'reference'     => implode("\n", [
            'Civil Code of the Philippines, Art. 1654 — Obligations of the lessor',
            'Civil Code, Art. 1657 — Obligations of the lessee',
            'Civil Code, Art. 1673 — Grounds for judicial ejectment',
            'Republic Act No. 9653 (Rent Control Act of 2009)',
            '1997 Rules of Civil Procedure, Rule 70 — Forcible entry and unlawful detainer',
            KP_REF_LGC_408,
        ]),
    ],

    [
        'title'         => 'Swindling or Estafa (Small Amount)',
        'category'      => 'Criminal',
        'summary'       => 'Being cheated out of money or property through deceit or abuse of trust.',
        'offense_class' => 'Depends on the amount',
        'report_to'     => 'Barangay if small amount · Police / Prosecutor if large',
        'case_type'     => 'Swindling or Estafa (Small Amount)',
        'content'       => <<<'TXT'
## What it is
Estafa (swindling) is defrauding someone through deceit or abuse of confidence — for example, receiving money for a purpose and using it for something else, or obtaining money by false pretenses.

## Examples
- Someone took your payment for goods and never delivered or returned it
- A person used money entrusted to them for another purpose
- Online selling scams (these may also be cybercrimes)

## Where to report
- Barangay first, if the amount is small and the penalty is not more than 1 year. The barangay checks the amount when it reviews your complaint.
- Police or prosecutor, for large amounts.
- Online scams: PNP Anti-Cybercrime Group or NBI Cybercrime Division.

## What to bring
- Receipts, proof of payment or bank and e-wallet transfer records
- Messages, chats or posts showing the deal
- Names of witnesses

## What happens next
1. Mediation before the Punong Barangay.
2. Settlements usually involve returning the money.
3. If there is no settlement, a Certification to File Action is issued.

## Important
Simply failing to pay a debt is not estafa. There must be deceit or abuse of confidence (see Unpaid Debt or Money Claim).
TXT,
        'reference'     => implode("\n", [
            KP_REF_RPC . ', Art. 315 — Swindling (estafa) (penalties as amended by RA 10951)',
            KP_REF_RPC . ', Art. 316 — Other forms of swindling',
            KP_REF_RPC . ', Art. 318 — Other deceits',
            KP_REF_RA10951,
            'Republic Act No. 10175 (Cybercrime Prevention Act of 2012)',
            KP_REF_LGC_408,
        ]),
    ],

    [
        'title'         => 'Other Dispute',
        'category'      => 'Civil',
        'summary'       => 'Any other disagreement with a fellow resident that is not listed here.',
        'offense_class' => 'Depends on the dispute',
        'report_to'     => 'Barangay first',
        'case_type'     => 'Other Dispute',
        'content'       => <<<'TXT'
## What it is
The Katarungang Pambarangay covers all kinds of disputes between people living in the same city or municipality, except those excluded by law. If your concern is not listed in this app, it may still be handled by the barangay.

## Examples
- Damage caused by a neighbor's animals
- Disputes over shared water lines, drainage or right of way
- Disagreements over a business arrangement between neighbors

## Where to report
- Barangay first. When you file, describe the problem clearly so the barangay can check if it is covered. If it is not, the barangay will tell you where to bring it.

## What to bring
- Your valid ID
- Any documents, photos, messages or names of witnesses related to the dispute

## What happens next
1. The barangay reviews your complaint and checks if it is covered.
2. If covered, it proceeds to mediation before the Punong Barangay.
3. If not covered, the barangay records why and refers you to the proper office.
TXT,
        'reference'     => implode("\n", [
            KP_REF_LGC_408 . ' (all disputes are covered unless excluded)',
            KP_REF_HB_2022 . ', p. 25 ("lahat ng klase ng alitan, maliban…")',
            KP_REF_SC_1493,
        ]),
    ],
];
