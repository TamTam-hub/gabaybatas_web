"""
Build the KP Form 10–27 templates used by the Barangay portal.

Each template keeps the Waray header and case caption of the working
KP Form 9 (Summons) template, and adds the official KP form wording from
the Katarungang Pambarangay Handbook (LGSP/SALIGAN) with ${placeholders}
that includes/kp_form_data.php fills in.

To change the wording or layout of Forms 10–27, edit the FORMS section
below and rebuild, instead of editing the .docx files in Word (Word can
split ${placeholders} internally, which stops them from being filled).

Usage (from the gabaybatas folder, Python 3.12+):
    py tools/build_kp_templates.py storage/kp_templates/KP_Form_09_Summons.docx storage/kp_templates

This overwrites KP_Form_10 ... KP_Form_27 in storage/kp_templates (internal
numbers; forms 21-27 print as DILG 2025 Forms 20-A, 20-B and 21-25).
Forms 7, 8, 9, 9-A and 28 are not touched.
"""
import re
import sys
import zipfile
from pathlib import Path

SRC, OUT = Path(sys.argv[1]), Path(sys.argv[2])
OUT.mkdir(parents=True, exist_ok=True)

src_zip = zipfile.ZipFile(SRC)
SRC_ENTRIES = [(i.filename, src_zip.read(i.filename)) for i in src_zip.infolist()]
doc_xml = src_zip.read("word/document.xml").decode("utf-8")
head, rest = doc_xml.split("<w:body>", 1)
body, tail = rest.split("</w:body>", 1)
elements = re.findall(r"<w:p[ >].*?</w:p>|<w:p/>|<w:tbl>.*?</w:tbl>|<w:sectPr.*?</w:sectPr>", body, re.S)
sect_pr = elements[-1]
assert sect_pr.startswith("<w:sectPr")
assert "Guin-rereklamo" in re.sub(r"<[^>]+>", "", elements[22])
header_caption = elements[:23]   # KP Form No., logo, Waray header, case caption

FONT = '<w:rFonts w:ascii="Bookman Old Style" w:hAnsi="Bookman Old Style"/>'


def esc(text):
    return text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


def run(text, bold=False, underline=False, size=22):
    rpr = f"<w:rPr>{FONT}{'<w:b/>' if bold else ''}{'<w:u w:val=\"single\"/>' if underline else ''}<w:sz w:val=\"{size}\"/><w:szCs w:val=\"{size}\"/></w:rPr>"
    parts = []
    for i, chunk in enumerate(text.split("\t")):
        if i:
            parts.append(f"<w:r>{rpr}<w:tab/></w:r>")
        if chunk:
            parts.append(f'<w:r>{rpr}<w:t xml:space="preserve">{esc(chunk)}</w:t></w:r>')
    return "".join(parts)


def rich(text, size=22):
    """**bold** segments; everything else plain."""
    out = []
    for i, seg in enumerate(re.split(r"\*\*", text)):
        if seg:
            out.append(run(seg, bold=bool(i % 2), size=size))
    return "".join(out)


def para(text="", jc="both", left=0, first=0, before=0, after=0, size=22, keep_next=False):
    ppr = f'<w:pPr>{"<w:keepNext/>" if keep_next else ""}<w:spacing w:before="{before}" w:after="{after}"/>'
    if left or first:
        ppr += f'<w:ind w:left="{left}"' + (f' w:firstLine="{first}"' if first > 0 else (f' w:hanging="{-first}"' if first < 0 else "")) + "/>"
    ppr += f'<w:jc w:val="{jc}"/><w:rPr>{FONT}<w:sz w:val="{size}"/><w:szCs w:val="{size}"/></w:rPr></w:pPr>'
    return f"<w:p>{ppr}{rich(text, size)}</w:p>"


def blank():
    return para("")


def title(*lines):
    return [blank()] + [para(f"**{line}**", jc="center") for line in lines] + [blank()]


def body_p(text):
    return para(text, jc="both", first=720, after=120)


def item(num, text):
    return para(f"{num}.\t{text}", jc="both", left=1080, first=-360, after=80)


def _pb_signature_drawing():
    """The Punong Barangay's signature picture from KP Form 9, re-anchored so
    it sits on a centred signature line (see signature(pb_signature=True))."""
    drawings = re.findall(r'<w:drawing>.*?</w:drawing>', doc_xml, re.S)
    found = [d for d in drawings if re.search(r'<wp:docPr id="\d+" name="Picture 6"', d)]
    assert len(found) == 1, "Form 9 signature picture (Picture 6) not found"
    drawing = found[0]
    assert 'r:embed="rId7"' in drawing  # media/image3.png, the captain's signature
    drawing = re.sub(r'<wp:positionH relativeFrom="\w+"><wp:posOffset>-?\d+</wp:posOffset>',
                     '<wp:positionH relativeFrom="margin"><wp:posOffset>3657600</wp:posOffset>', drawing)
    drawing = re.sub(r'<wp:positionV relativeFrom="\w+"><wp:posOffset>-?\d+</wp:posOffset>',
                     '<wp:positionV relativeFrom="paragraph"><wp:posOffset>-402336</wp:posOffset>', drawing)
    drawing = re.sub(r' wp14:(anchorId|editId)="[0-9A-F]+"', '', drawing)
    return re.sub(r'<wp:docPr id="\d+"', '<wp:docPr id="901"', drawing)


def signature(name_ph, title_ph, label=None, pb_signature=False):
    out = [blank()]
    if label:
        out.append(para(label, jc="left"))
    if pb_signature:
        # Kept only when the Punong Barangay signs (block set in kp_form_data.php).
        out += [
            para("${pb_signature}", jc="left"),
            '<w:p><w:pPr><w:keepNext/><w:spacing w:before="0" w:after="0" w:line="20" w:lineRule="exact"/>'
            f'<w:rPr><w:sz w:val="2"/></w:rPr></w:pPr><w:r><w:rPr><w:noProof/><w:sz w:val="2"/></w:rPr>{_pb_signature_drawing()}</w:r></w:p>',
            para("${/pb_signature}", jc="left"),
        ]
    out += [
        para("______________________________", jc="center", left=4680, keep_next=True),
        para(f"**{name_ph}**", jc="center", left=4680, keep_next=True),
        para(title_ph, jc="center", left=4680),
    ]
    return out


def cell(paragraphs, width=4600):
    inner = "".join(paragraphs) or para("")
    return f'<w:tc><w:tcPr><w:tcW w:w="{width}" w:type="dxa"/></w:tcPr>{inner}</w:tc>'


def two_col(left, right):
    """Borderless two-column table (complainant / respondent signatures)."""
    grid = '<w:tblGrid><w:gridCol w:w="4600"/><w:gridCol w:w="4600"/></w:tblGrid>'
    tblpr = ('<w:tblPr><w:tblW w:w="9200" w:type="dxa"/><w:tblBorders>'
             + "".join(f'<w:{b} w:val="nil"/>' for b in ("top", "left", "bottom", "right", "insideH", "insideV"))
             + '</w:tblBorders><w:tblLayout w:type="fixed"/></w:tblPr>')
    return f"<w:tbl>{tblpr}{grid}<w:tr>{cell(left)}{cell(right)}</w:tr></w:tbl>"


def party_signatures(left_label="Complainant/s", right_label="Respondent/s",
                     left_name="${complainant_name}", right_name="${respondent_name}"):
    def side(label, name):
        return [para(f"**{label}:**", jc="left"), blank(),
                para("____________________________", jc="left", keep_next=True),
                para(name, jc="left")]
    return [blank(), two_col(side(left_label, left_name), side(right_label, right_name))]


def notified(left_label="Complainant/s", right_label="Respondent/s"):
    def side(label):
        return [para(f"**{label}:**", jc="left"), blank(),
                para("____________________________", jc="left"),
                para("____________________________", jc="left")]
    return [blank(), para("Notified this ________ day of ________________, 20____.", jc="left"),
            two_col(side(left_label), side(right_label))]


def to_parties():
    return [para("**TO:**", jc="left"),
            two_col([para("${complainant_name}", jc="left"), para("Complainant/s", jc="left")],
                    [para("${respondent_name}", jc="left"), para("Respondent/s", jc="left")]),
            blank()]


def to_one(name_ph, role):
    return [para("**TO:**\t${" + name_ph + "}", jc="left"), para("\t" + role, jc="left"), blank()]


ISSUED = "This ${date_issued_day} day of ${date_issued_month}, ${date_issued_year}."
HEARING = "on the ${hearing_date_day} day of ${hearing_month}, ${hearing_date_year}, at ${hearing_time}"

FORMS = {
    10: ("KP_Form_10_Notice_Pangkat.docx", "OPISINA HAN PUNONG BARANGAY", [
        *title("NOTICE FOR CONSTITUTION OF PANGKAT"),
        *to_parties(),
        body_p(f"You are hereby required to appear before me {HEARING} for the constitution of the Pangkat ng Tagapagkasundo which shall conciliate your dispute. Should you fail to agree on the Pangkat membership or to appear on the aforesaid date for the constitution of the Pangkat, I shall determine the membership thereof by drawing lots."),
        body_p(ISSUED),
        *signature("${punong_barangay}", "Punong Barangay"),
        *notified(),
    ]),
    11: ("KP_Form_11_Notice_Pangkat_Member.docx", None, [
        *title("NOTICE TO CHOSEN PANGKAT MEMBER"),
        para("${date_issued}", jc="right"), blank(),
        *to_one("pangkat_member_name", ""),
        body_p("Notice is hereby given that you have been chosen member of the Pangkat ng Tagapagkasundo to amicably conciliate the dispute between the parties in the above-entitled case."),
        *signature("${punong_barangay}", "Punong Barangay"),
        blank(),
        para("Received this ________ day of ________________, 20____.", jc="left"),
        *signature("${pangkat_member_name}", "Pangkat Member"),
    ]),
    12: ("KP_Form_12_Notice_Hearing_Conciliation.docx", None, [
        *title("NOTICE OF HEARING", "(CONCILIATION PROCEEDINGS)"),
        *to_parties(),
        body_p(f"You are hereby required to appear before the Pangkat {HEARING} for a hearing of the above-entitled case."),
        body_p(ISSUED),
        *signature("${pangkat_chairman}", "Pangkat Chairman"),
        *notified(),
    ]),
    13: ("KP_Form_13_Subpoena.docx", None, [
        *title("S U B P O E N A"),
        para("**TO:**\t${witness_name}", jc="left"), para("\t${witness_address}", jc="left"), para("\tWitness/es", jc="left"), blank(),
        body_p(f"You are hereby commanded to appear before me {HEARING}, then and there to testify in the hearing of the above-captioned case."),
        body_p(ISSUED),
        *signature("${presiding_name}", "${presiding_title}", pb_signature=True),
    ]),
    14: ("KP_Form_14_Agreement_Arbitration.docx", None, [
        *title("AGREEMENT FOR ARBITRATION"),
        body_p("We hereby agree to submit our dispute for arbitration to the ${arbitrator_body} and bind ourselves to comply with the award that may be rendered thereon. We have made this agreement freely with a full understanding of its nature and consequences."),
        body_p("Entered into this ${agreement_day} day of ${agreement_month}, ${agreement_year}."),
        *party_signatures(),
        *title("ATTESTATION"),
        body_p("I hereby certify that the foregoing Agreement for Arbitration was entered into by the parties freely and voluntarily, after I had explained to them the nature and the consequences of such agreement."),
        *signature("${arbitrator_name}", "${arbitrator_title}"),
    ]),
    15: ("KP_Form_15_Arbitration_Award.docx", None, [
        *title("ARBITRATION AWARD"),
        body_p("After hearing the testimonies given and careful examination of the evidence presented in this case, award is hereby made as follows:"),
        para("${award_text}", jc="both", left=720, after=120),
        body_p("Made this ${award_day} day of ${award_month}, ${award_year} at ${award_place}."),
        *signature("${arbitrator_name}", "${arbitrator_title}"),
        para("${pangkat_award_members}", jc="left"),
        two_col([para("______________________________", jc="center"), para("**${pangkat_member_1}**", jc="center"), para("Member", jc="center")],
                [para("______________________________", jc="center"), para("**${pangkat_member_2}**", jc="center"), para("Member", jc="center")]),
        para("${/pangkat_award_members}", jc="left"),
        *signature("${attester_name}", "${attester_title}", label="**ATTESTED:**"),
    ]),
    16: ("KP_Form_16_Amicable_Settlement.docx", None, [
        *title("AMICABLE SETTLEMENT"),
        body_p("We, complainant/s and respondent/s in the above-captioned case, do hereby agree to settle our dispute as follows:"),
        para("${settlement_terms}", jc="both", left=720, after=120),
        body_p("and bind ourselves to comply honestly and faithfully with the above terms of settlement."),
        body_p("Entered into this ${settlement_day} day of ${settlement_month}, ${settlement_year}."),
        *party_signatures(),
        *title("ATTESTATION"),
        body_p("I hereby certify that the foregoing amicable settlement was entered into by the parties freely and voluntarily, after I had explained to them the nature and consequence of such settlement."),
        *signature("${presiding_name}", "${presiding_title}"),
    ]),
    17: ("KP_Form_17_Repudiation.docx", None, [
        *title("R E P U D I A T I O N"),
        body_p("I/WE hereby repudiate the ${repudiation_object} on the ground that my/our consent was vitiated by:"),
        para("[ ${chk_fraud} ]\tFraud. (State details) ${details_fraud}", jc="both", left=1080, first=-720, after=80),
        para("[ ${chk_violence} ]\tViolence. (State details) ${details_violence}", jc="both", left=1080, first=-720, after=80),
        para("[ ${chk_intimidation} ]\tIntimidation. (State details) ${details_intimidation}", jc="both", left=1080, first=-720, after=120),
        body_p("This ${sworn_day} day of ${sworn_month}, ${sworn_year}."),
        *party_signatures(left_name="${repudiating_complainant}", right_name="${repudiating_respondent}"),
        blank(),
        body_p("SUBSCRIBED AND SWORN TO before me this ${sworn_day} day of ${sworn_month}, ${sworn_year} at ${place}."),
        *signature("${presiding_name}", "${presiding_title}"),
        blank(),
        para("Received and filed* this ________ day of ________________, 20____.", jc="left"),
        *signature("${punong_barangay}", "Punong Barangay"),
        blank(),
        para("* Failure to repudiate the settlement or the arbitration agreement within the time limits respectively set (ten [10] days from the date of settlement and five [5] days from the date of arbitration agreement) shall be deemed a waiver of the right to challenge on said grounds.", jc="both", size=18),
    ]),
    18: ("KP_Form_18_Notice_Complainant_No_Show.docx", None, [
        *title("NOTICE OF HEARING", "(RE: FAILURE TO APPEAR)"),
        *to_one("complainant_name", "Complainant/s"),
        body_p(f"You are hereby required to appear before ${{appear_before}} {HEARING} to explain why you failed to appear for ${{proceeding}} scheduled on ${{missed_hearing_date}} and why your complaint should not be dismissed, a certificate to bar the filing of your action on court/government office should not be issued, and contempt proceedings should not be initiated in court for willful failure or refusal to appear before the ${{forum}}."),
        body_p(ISSUED),
        *signature("${presiding_name}", "${presiding_title}"),
        *notified(),
    ]),
    19: ("KP_Form_19_Notice_Respondent_No_Show.docx", None, [
        *title("NOTICE OF HEARING", "(RE: FAILURE TO APPEAR)"),
        *to_one("respondent_name", "Respondent/s"),
        body_p(f"You are hereby required to appear before ${{appear_before}} {HEARING} to explain why you failed to appear for ${{proceeding}} scheduled on ${{missed_hearing_date}} and why your counterclaim (if any) arising from the complaint should not be dismissed, a certificate to bar the filing of said counterclaim in court/government office should not be issued, and contempt proceedings should not be initiated in court for willful failure or refusal to appear before the ${{forum}}."),
        body_p(ISSUED),
        *signature("${presiding_name}", "${presiding_title}"),
        *notified("Respondent/s", "Complainant/s"),
    ]),
    20: ("KP_Form_20_Cert_File_Action_Lupon.docx", None, [
        *title("CERTIFICATION TO FILE ACTION"),
        para("This is to certify that:", jc="left", after=120),
        item(1, "There has been a personal confrontation between the parties before the ${forum};"),
        item(2, "${cert_item_2}"),
        item(3, "${cert_item_3}"),
        item(4, "Therefore, the corresponding complaint for the dispute may now be filed in court/government office."),
        blank(), body_p(ISSUED),
        *signature("${lupon_secretary}", "Lupon Secretary"),
        *signature("${punong_barangay}", "Lupon Chairman", label="**ATTESTED:**"),
    ]),
    21: ("KP_Form_21_Cert_File_Action_Pangkat.docx", None, [
        *title("CERTIFICATION TO FILE ACTION"),
        para("This is to certify that:", jc="left", after=120),
        item(1, "${mediation_fact}"),
        item(2, "The Pangkat ng Tagapagkasundo was constituted but the personal confrontation before the Pangkat likewise did not result into a settlement; and"),
        item(3, "Therefore, the corresponding complaint for the dispute may now be filed in court/government office."),
        blank(), body_p(ISSUED),
        *signature("${pangkat_secretary}", "Pangkat Secretary"),
        *signature("${pangkat_chairman}", "Pangkat Chairman", label="**ATTESTED BY:**"),
    ]),
    22: ("KP_Form_22_Cert_File_Action_Respondent_Absent.docx", None, [
        *title("CERTIFICATION TO FILE ACTION"),
        para("This is to certify that:", jc="left", after=120),
        item(1, "${mediation_fact}"),
        item(2, "The Punong Barangay set the meeting of the parties for the constitution of the Pangkat;"),
        item(3, "The respondent willfully failed or refused to appear without justifiable reason at the conciliation proceedings before the Pangkat; and"),
        item(4, "Therefore, the corresponding complaint for the dispute may now be filed in court/government office."),
        blank(), body_p(ISSUED),
        *signature("${pangkat_secretary}", "Pangkat Secretary"),
        *signature("${pangkat_chairman}", "Pangkat Chairman", label="**ATTESTED BY:**"),
    ]),
    23: ("KP_Form_23_Cert_Bar_Action.docx", None, [
        *title("CERTIFICATION TO BAR ACTION"),
        body_p("This is to certify that the above-captioned case was dismissed pursuant to the Order dated ${dismissal_date}, for complainant/s ${complainant_name}'s willful failure or refusal to appear for hearing before the ${forum} and therefore complainant/s is/are barred from filing an action in court/government office."),
        body_p(ISSUED),
        *signature("${cert_signer_name}", "${cert_signer_title}"),
        *signature("${cert_attester_name}", "${cert_attester_title}", label="**ATTESTED:**"),
    ]),
    24: ("KP_Form_24_Cert_Bar_Counterclaim.docx", None, [
        *title("CERTIFICATION TO BAR COUNTERCLAIM"),
        body_p("This is to certify that after prior notice and hearing, the respondent/s ${respondent_name} have been found to have willfully failed or refused to appear without justifiable reason before the ${forum} and therefore respondent/s is/are barred from filing his/their counterclaim (if any) arising from the complaint in court/government office."),
        body_p(ISSUED),
        *signature("${cert_signer_name}", "${cert_signer_title}"),
        *signature("${cert_attester_name}", "${cert_attester_title}", label="**ATTESTED:**"),
    ]),
    25: ("KP_Form_25_Motion_Execution.docx", None, [
        *title("MOTION FOR EXECUTION"),
        para("${movant_title} state/s as follows:", jc="left", after=120),
        item(1, "On ${settlement_date_formatted} the parties in this case ${final_act};"),
        item(2, "The period of ten (10) days from the above-stated date has expired without any of the parties filing ${challenge_not_filed}; and"),
        item(3, "The ${settlement_kind} is now final and executory."),
        blank(),
        body_p("WHEREFORE, ${movant_title} request/s that the corresponding writ of execution be issued by the Lupon Chairman in this case."),
        para("${motion_date}", jc="left"),
        *signature("${moving_party_name}", "${movant_title}"),
    ]),
    26: ("KP_Form_26_Notice_Hearing_Execution.docx", None, [
        *title("NOTICE OF HEARING", "(RE: MOTION FOR EXECUTION)"),
        *to_parties(),
        body_p(f"You are hereby required to appear before me {HEARING} for the hearing of the motion for execution, copy of which is attached hereto, filed by ${{moving_party_name}}."),
        para("${date_issued}", jc="left"),
        *signature("${punong_barangay}", "Punong Barangay / Lupon Chairman"),
        *notified(),
    ]),
    27: ("KP_Form_27_Notice_Execution.docx", None, [
        *title("NOTICE OF EXECUTION"),
        *to_parties(),
        body_p("WHEREAS, on ${settlement_date_formatted}, ${final_event};"),
        body_p("WHEREAS, the ${terms_label} read:"),
        para("${settlement_terms}", jc="both", left=720, after=120),
        body_p("The said ${settlement_kind} is now final and executory;"),
        body_p("WHEREAS, the party obliged ${obliged_party} has not complied voluntarily with the aforestated ${settlement_kind}, within the period of five (5) days from the date of hearing on the motion for execution;"),
        body_p("NOW, THEREFORE, in behalf of the Lupong Tagapamayapa and by virtue of the powers vested in me and the Lupon by the Katarungang Pambarangay Law and Rules, I shall cause to be realized from the goods and personal property of ${obliged_party} the sum of ${execution_amount} ${amount_basis}, unless voluntary compliance of said settlement or award shall have been made upon receipt hereof."),
        body_p("Signed this ${date_issued_day} day of ${date_issued_month}, ${date_issued_year}."),
        *signature("${punong_barangay}", "Punong Barangay"),
        blank(),
        para("**Copy furnished:**", jc="left"),
        two_col([para("${complainant_name}", jc="left"), para("Complainant/s", jc="left")],
                [para("${respondent_name}", jc="left"), para("Respondent/s", jc="left")]),
    ]),
}

# Printed form numbers follow the DILG 2025 downloadable KP forms (DOJ KP Rules);
# the FORMS keys above are the system's internal form numbers.
PRINTED_NUMBER = {21: "20-A", 22: "20-B", 23: "21", 24: "22", 25: "23", 26: "24", 27: "25"}

for number, (filename, office, body_parts) in FORMS.items():
    printed = PRINTED_NUMBER.get(number, str(number))
    kept = []
    for i, el in enumerate(header_caption):
        el = re.sub(r"<w:bookmark(Start|End)[^>]*/>", "", el)
        if i == 0:
            el = el.replace("<w:t>9</w:t>", f"<w:t>{printed}</w:t>")
            assert f"<w:t>{printed}</w:t>" in el
        if "OPISINA HAN LUPON TAGAPAMAYAPA" in el:
            el = el.replace("OPISINA HAN LUPON TAGAPAMAYAPA", office or "OPISINA HAN LUPONG TAGAPAMAYAPA")
        kept.append(el)
    new_body = "".join(kept) + "".join(body_parts) + sect_pr
    new_xml = head + "<w:body>" + new_body + "</w:body>" + tail

    out_path = OUT / filename
    with zipfile.ZipFile(out_path, "w", zipfile.ZIP_DEFLATED) as zout:
        for name, data in SRC_ENTRIES:
            zout.writestr(name, new_xml.encode("utf-8") if name == "word/document.xml" else data)
    print(f"built {filename}")
