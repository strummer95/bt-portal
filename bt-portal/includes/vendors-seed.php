<?php
/**
 * BT Portal — one-time vendor import from the old spreadsheet.
 *
 * The source sheet had columns shifted on roughly a dozen rows: credentials
 * sitting in the Address column, account numbers in the Fax column, logins in
 * the Phone column. Those are corrected here rather than imported wrong, and
 * anything genuinely ambiguous was left in Notes instead of being guessed at.
 *
 * Runs once. If the table already has rows it does nothing, so re-activating
 * the plugin will never duplicate or overwrite edits made in the portal.
 */

if (!defined('ABSPATH')) exit;

function btp_vendor_seed() {
    global $wpdb;
    $t = btp_vendor_table();

    if ((int) $wpdb->get_var("SELECT COUNT(*) FROM $t") > 0) return;

    foreach (btp_vendor_seed_data() as $v) {
        $secret = isset($v['secret']) ? $v['secret'] : '';
        unset($v['secret']);

        $wpdb->insert($t, array_merge(array(
            'name'       => '',
            'category'   => 'Other',
            'phone'      => '',
            'fax'        => '',
            'account_no' => '',
            'login'      => '',
            'website'    => '',
            'address'    => '',
            'notes'      => '',
            'secret'     => $secret === '' ? '' : btp_vendor_encrypt($secret),
            'updated_at' => current_time('mysql'),
        ), $v));
    }
}

function btp_vendor_seed_data() {
    return array(

    // ── APPAREL & BLANKS ──────────────────────────────────────────────────
    array('name'=>'S&S Activewear','category'=>'Apparel','phone'=>'630-679-9940 *2','fax'=>'630-679-9941',
        'account_no'=>'11358 (BT2) · 11351 (BT1) · 11527 (BT3)','login'=>'vendors@boomerts.com','secret'=>'Boomer$012',
        'address'=>"2 Gateway Ct\nBolingbrook, IL 60440",
        'notes'=>"Send RA requests to tboyd@ssactivewear.com — include account #, invoice # (if known), styles, colors, quantities and reason for the RA."),

    array('name'=>'SanMar','category'=>'Apparel','phone'=>'1-800-426-6399 *1','fax'=>'800-884-8577',
        'account_no'=>'108175','login'=>'boomerts','secret'=>'Boomer123',
        'address'=>"PO Box 529\nPreston, WA 98050"),

    array('name'=>'Broder Bros. / alphabroder','category'=>'Apparel','phone'=>'800-521-0850 *1','fax'=>'800-521-1251',
        'account_no'=>'1416719','login'=>'ryank','secret'=>'Boomer123',
        'address'=>"27811 Network Place\nChicago, IL 60673-1278",
        'notes'=>"Beth — BBELICH@ALPHABRODER.COM\nBolingbrook showroom: 633-4020 or 633-4021"),

    array('name'=>'Augusta Sportswear','category'=>'Apparel','phone'=>'800-237-6695 *1',
        'account_no'=>'113158','login'=>'HV9263','secret'=>'Digital$0203',
        'address'=>"PO Box 532095\nAtlanta, GA 30353-2095"),

    array('name'=>'Badger Sportswear','category'=>'Apparel','phone'=>'888-871-0990',
        'account_no'=>'2709','login'=>'brittney@boomerts.com','secret'=>'Boomer1234',
        'address'=>"850 Meacham Road\nStatesville, NC 28677",
        'notes'=>"Rep: John Smith — 847-516-8119, cell 847-612-2323"),

    array('name'=>'Holloway Sportswear','category'=>'Apparel','phone'=>'800-331-5156','fax'=>'937-497-8080',
        'account_no'=>'H117840001','login'=>'H117840001','secret'=>'Boomer1234',
        'address'=>"39228 Treasury Center\nChicago, IL 60694-9200",
        'notes'=>'Contact: Rick Campbell'),

    array('name'=>'Boxercraft','category'=>'Apparel','phone'=>'800-914-7774 *215','fax'=>'404-351-3994',
        'account_no'=>'C03265','login'=>'C03265','secret'=>'Boomer1234',
        'address'=>"PO Box 20016\nAtlanta, GA 30325",
        'notes'=>"Account email: sales@boomerts.com\nRep: ron@boxercraft.com"),

    array('name'=>'CHAMPRO','category'=>'Apparel','phone'=>'847-229-4050','fax'=>'800-747-7117',
        'account_no'=>'01-booILo','login'=>'dillon@boomerts.com','secret'=>'Digital$$$1505!',
        'address'=>"1175 Wheeling Rd\nWheeling, IL 60090",
        'notes'=>'Rep: Jared — jbuzan@champrosports.com'),

    array('name'=>'A4','category'=>'Apparel','phone'=>'888-464-3824 *1','fax'=>'323-583-6565',
        'account_no'=>'4506','login'=>'boomer@boomerts.com','secret'=>'Digital$0203',
        'address'=>'Los Angeles, CA'),

    array('name'=>'Richardson Cap','category'=>'Apparel','phone'=>'(541) 687-1818','fax'=>'(541) 687-1130',
        'account_no'=>'bt605ni','login'=>'ryan@boomerts.com','secret'=>'Boomer123',
        'address'=>"100 Cap Court, PO Box 2440\nEugene, OR 97402",
        'notes'=>"FOUR WEEKS for stock caps.\nEmail orders to sales@richardsoncap.com\nAlt fax: 1-800-451-9203\nLogin is the Online Dealer Zone."),

    array('name'=>'Pacific Headwear','category'=>'Apparel','phone'=>'800-207-0981','fax'=>'541-685-1033',
        'account_no'=>'B00MERT','login'=>'boomertjose','secret'=>'Boomer123',
        'address'=>"PO Box 25807, 1010 Wilson St\nEugene, OR 97402",
        'notes'=>'Account number uses zeroes, not the letter O.'),

    array('name'=>'Charles River','category'=>'Apparel','login'=>'boomer@boomerts.com','secret'=>'Boomer123'),

    array('name'=>'BAW','category'=>'Apparel','account_no'=>'6308510000','login'=>'boomertee.','secret'=>'Boomer$012',
        'website'=>'https://www.bawonline.com/',
        'notes'=>'Username appears to end in a full stop in the old sheet — verify on next login.'),

    array('name'=>'Eagle USA','category'=>'Apparel','phone'=>'800-233-6097','fax'=>'919-365-7345',
        'account_no'=>'boo1','login'=>'abc123','secret'=>'eagleusa',
        'address'=>"PO Box 127, 375 East Third Street\nWendell, NC 27591"),

    array('name'=>'Dyenomite','category'=>'Apparel','phone'=>'614-767-1958 x750','account_no'=>'CO3802',
        'notes'=>'Log in with your own email address — no shared account.'),

    array('name'=>'Mizuno','category'=>'Apparel','account_no'=>'210839','login'=>'ryan@boomerts.com','secret'=>'Digital$0203',
        'website'=>'https://b2b.mizunousa.com/home'),

    array('name'=>'Staton','category'=>'Apparel','phone'=>'1-800-888-8888 *1','account_no'=>'6304281900',
        'login'=>'boomerts','secret'=>'digital01'),

    array('name'=>'Pennant','category'=>'Apparel','phone'=>'1-800-648-6505',
        'login'=>'vendors@boomerts.com','secret'=>'Boomer$012'),

    array('name'=>'White Mountain Pennant','category'=>'Apparel','phone'=>'800-648-6505',
        'account_no'=>'C000275','login'=>'jennifer@boomerts.com','secret'=>'823joiy',
        'notes'=>'Shares a phone number with Pennant — may be the same company.'),

    array('name'=>'Whispering Pine Sportswear','category'=>'Apparel','phone'=>'1-800-548-4710','fax'=>'1-800-443-4602',
        'account_no'=>'OO6422','login'=>'boomerts','secret'=>'digital1',
        'address'=>"1609 Rocky River Road North\nMonroe, NC 28110",
        'notes'=>'Phone passphrase: gary'),

    array('name'=>'Saxon','category'=>'Apparel','phone'=>'866-879-8766','fax'=>'800-727-5506',
        'account_no'=>'boo01','address'=>'Brantford, Ontario, Canada'),

    array('name'=>'SM Cristall','category'=>'Apparel','phone'=>'800-800-9983','website'=>'https://www.smcristall.com/'),

    array('name'=>'American Apparel','category'=>'Apparel','phone'=>'213-488-0226','fax'=>'213-201-3059',
        'account_no'=>'10025635','notes'=>'Fax in the old sheet read 21-201-3059 — digit likely missing.'),

    array('name'=>'Venus Knitting Mills','category'=>'Apparel','phone'=>'800-866-4200','fax'=>'630-325-0174',
        'account_no'=>'3B5250'),

    array('name'=>"Virginia T's",'category'=>'Apparel','phone'=>'800-289-8099','account_no'=>'6306277400',
        'notes'=>'Contact: S. Day'),

    array('name'=>'Rawlings Apparel Group','category'=>'Apparel','phone'=>'1-636-349-3500',
        'address'=>"PO Box 2200\nSt. Louis, MO 63126"),

    array('name'=>'Numo Manufacturing','category'=>'Apparel','phone'=>'800-253-0434','fax'=>'972-288-1629',
        'address'=>"PO Box 671318\nDallas, TX 75267",'notes'=>'Contact: Jeff'),

    array('name'=>'Forum','category'=>'Apparel','phone'=>'873-8508','fax'=>'873-8536',
        'address'=>"1900 S. Highland Avenue\nLombard, IL 60148",'notes'=>'Contact: Clare Mensik'),

    array('name'=>'Vital','category'=>'Apparel','phone'=>'800-777-8482 *1 *1',
        'address'=>"7 Norden Lane\nHuntington Station, NY 11746"),

    // ── DECORATION & DIGITIZING ───────────────────────────────────────────
    array('name'=>'Transfer Express','category'=>'Decoration','phone'=>'800-622-2280','fax'=>'800-833-3877',
        'account_no'=>'17624','login'=>'17624','secret'=>'Digital123',
        'address'=>"7650 Tyler Blvd.\nMentor, OH 44060"),

    array('name'=>"STAHL'S",'category'=>'Decoration','phone'=>'800-478-2457','fax'=>'800-346-2216',
        'account_no'=>'317624','login'=>'boomer@boomerts.com','secret'=>'Boomer123',
        'address'=>"PO Box 628\nSt. Clair Shores, MI 48080-0628"),

    array('name'=>'613 Originals — Heat Transfer','category'=>'Decoration','phone'=>'201-316-1919',
        'login'=>'boomer@boomerts.com','secret'=>'Boomer123'),

    array('name'=>'Eagle Digitizing','category'=>'Decoration','phone'=>'1-866-242-4888',
        'login'=>'brittney@boomerts.com','secret'=>'87627',
        'website'=>'https://eagledigitizing.com/','notes'=>'Contact: Suzie'),

    array('name'=>'Inclined Graphics','category'=>'Decoration',
        'login'=>'inclinedgraphics@gmail.com',
        'notes'=>"\$5 digitizing.\nEmail a JPG or PNG, attn Peter."),

    array('name'=>'Embroidery.com','category'=>'Decoration','login'=>'brittney@boomerts.com','secret'=>'champs01'),

    array('name'=>'EmbroideryDesigns.com','category'=>'Decoration',
        'notes'=>'No details on the old sheet.'),

    array('name'=>'Unique Assembly & Decorating','category'=>'Decoration','phone'=>'630-629-4009','fax'=>'630-629-4101'),

    array('name'=>'Allied Hoops','category'=>'Decoration','account_no'=>'z',
        'login'=>'boomer@boomerts.com','secret'=>'Boomer$012','website'=>'http://www.alliedi.com/',
        'notes'=>'Tajima hoops.'),

    // ── EQUIPMENT ─────────────────────────────────────────────────────────
    array('name'=>'Brother DTG — Supplies & Parts','category'=>'Equipment','phone'=>'855-862-1148',
        'account_no'=>'1000003009','login'=>'boomer@boomerts.com','secret'=>'Boomer$012',
        'website'=>'https://partnerportal.brother-usa.com/'),

    array('name'=>'Brother Support','category'=>'Equipment','phone'=>'877-427-6843','account_no'=>'726016'),

    array('name'=>'Coldesi','category'=>'Equipment','phone'=>'800-826-6332 *1','fax'=>'251-633-3876',
        'account_no'=>'245024','login'=>'boomer@boomerts.com','secret'=>'Digital$0203',
        'website'=>'https://dyetrans.com/',
        'address'=>"5600 Commerce Blvd East\nMobile, AL 36619-9214"),

    array('name'=>'Conde','category'=>'Equipment','phone'=>'800-826-6332','account_no'=>'245024',
        'login'=>'boomer@boomerts.com','secret'=>'Boomer$012',
        'notes'=>'Shares an account number and phone with Coldesi.'),

    array('name'=>'Roland DGA','category'=>'Equipment','login'=>'vendors@boomerts.com','secret'=>'Boomer$012',
        'website'=>'https://dgastore.rolanddga.com/'),

    array('name'=>'Hirsch International','category'=>'Equipment','phone'=>'866-447-7244',
        'account_no'=>'116463','address'=>"PO Box 9665\nUniondale, NY 11555-9665",
        'notes'=>"Tech support: dial 3.\nLocal: 847-647-5055\nHave the machine model number ready."),

    array('name'=>'Magic Touch USA','category'=>'Equipment','phone'=>'847-612-1662',
        'notes'=>'Call orders in to Joe Paxton.'),

    // ── SUPPLIES ──────────────────────────────────────────────────────────
    array('name'=>'All American Print Supply (DTF — Prestige)','category'=>'Supplies','phone'=>'(215) 634-2235',
        'login'=>'boomer@boomerts.com','secret'=>'Digital$0203','website'=>'https://aaprintsupplyco.com/'),

    array('name'=>'Imprintables Warehouse','category'=>'Supplies','phone'=>'800-347-0068','fax'=>'724-583-0426',
        'account_no'=>'BOOI16','login'=>'boomer@boomerts.com','secret'=>'Boomer123',
        'notes'=>'Rep: Mark — 419-616-3324'),

    array('name'=>'ULINE','category'=>'Supplies','phone'=>'800-295-5510','account_no'=>'1663590',
        'login'=>'boomer@boomerts.com','secret'=>'Champs01',
        'address'=>"2200 S. Lakeside Drive\nWaukegan, IL 60085"),

    array('name'=>'Office Depot','category'=>'Supplies','phone'=>'630-548-9973','account_no'=>'52162721',
        'login'=>'boomer@boomerts.com','secret'=>'Digital$02','website'=>'https://www.odpbusiness.com/',
        'notes'=>"PASSWORD EXPIRES EVERY 90 DAYS.\nReps: Falbo, Anthony or Campbell, Alton — 800-613-4624"),

    array('name'=>'Rhinestone Exchange','category'=>'Supplies','login'=>'boomerts','secret'=>'champs01',
        'website'=>'https://rhinestoneexchange.com/'),

    // ── PROMO ─────────────────────────────────────────────────────────────
    array('name'=>'Garyline','category'=>'Promo','phone'=>'800-227-4279 *6 *1','fax'=>'888-864-9932',
        'account_no'=>'BOO013','login'=>'boomer@boomerts.com','secret'=>'Boomer$0203',
        'address'=>"1340 Viele Ave\nBronx, NY 10474-7124"),

    array('name'=>'Norwood Promotional Products','category'=>'Promo','phone'=>'877-NORWOOD','fax'=>'888-207-2507',
        'login'=>'boomerts','secret'=>'champs01',
        'address'=>"5335 Castroville Road\nSan Antonio, TX 78227"),

    array('name'=>'Notes, Inc.','category'=>'Promo','phone'=>'1-800-729-2823','fax'=>'1-315-437-3634',
        'address'=>"6761 Thompson Road\nN. Syracuse, NY 13211"),

    // ── SHIPPING ──────────────────────────────────────────────────────────
    array('name'=>'FedEx','category'=>'Shipping','phone'=>'800-622-1147','fax'=>'800-548-3020',
        'account_no'=>'362303311','login'=>'boomerts','secret'=>'Boomer$012',
        'address'=>"PO Box 1140\nMemphis, TN 38101-1140",
        'notes'=>'Crate FedEx account number: 857834194'),

    array('name'=>'UPS','category'=>'Shipping','phone'=>'1-800-377-4877','login'=>'boomerts','secret'=>'Champs01',
        'notes'=>'The first letter of the password is a capital.'),

    array('name'=>'USPS','category'=>'Shipping','login'=>'boomertee@me.com','secret'=>'Boomer#012',
        'website'=>'https://www.usps.com/'),

    array('name'=>'Stamps.com','category'=>'Shipping','login'=>'boomertee','secret'=>'Digital$0203',
        'notes'=>'Account email: boomer@boomerts.com'),

    array('name'=>'ShipStation','category'=>'Shipping','login'=>'boomer@boomerts.com','secret'=>'Digital$0203',
        'website'=>'https://ship14.shipstation.com/'),

    array('name'=>'Quick Trans','category'=>'Shipping','phone'=>'800-572-4172','account_no'=>'11379',
        'login'=>'quest','secret'=>'68390',
        'address'=>"1701 S Eisenhower\nMason City, IA 50401",
        'notes'=>'Artwork: qtart@quicktrans.net'),

    array('name'=>'US Messenger','category'=>'Shipping','phone'=>'630-286-0550','account_no'=>'OD24071',
        'login'=>'boomerts','secret'=>'Speedy20!',
        'address'=>"7790 Quincy St.\nWillowbrook, IL 60527"),

    array('name'=>'Premier Transport (Messenger)','category'=>'Shipping','phone'=>'815-254-8935',
        'address'=>"23831 W Andrew Road\nPlainfield, IL 60585"),

    // ── SOFTWARE & ONLINE ─────────────────────────────────────────────────
    array('name'=>'Printavo','category'=>'Software','login'=>'ryan@boomerts.com','secret'=>'Boomer123',
        'notes'=>'Second account: customerservice@boomerts.com / Digital$0203'),

    array('name'=>'Order My Gear','category'=>'Software','login'=>'ryan@boomerts.com','secret'=>'Boomer123'),

    array('name'=>'Chipply','category'=>'Software','login'=>'boomer@boomerts.com','secret'=>'Digital$0203',
        'notes'=>'Contact: Ian Foster'),

    array('name'=>'GraphicsFlow','category'=>'Software','login'=>'ryan@boomerts.com','secret'=>'Digital$0203'),

    array('name'=>'CorelDRAW','category'=>'Software','login'=>'vendors@boomerts.com','secret'=>'Digital$01',
        'website'=>'https://www.coreldraw.com/en/support/your-account/'),

    array('name'=>'CadworxLive','category'=>'Software','login'=>'BOOMERTS','secret'=>'champs01',
        'website'=>'https://cadworxlive.com/'),

    array('name'=>'DistributorCentral','category'=>'Software','login'=>'boomerts','secret'=>'champs01',
        'website'=>'https://www.distributorcentral.com/'),

    array('name'=>'Mailchimp','category'=>'Software','login'=>'Boomertee','secret'=>'Boomer$012'),

    array('name'=>'WebWizer','category'=>'Software','phone'=>'858-292-2339',
        'address'=>"3914 Murphy Canyon Rd\nSan Diego, CA 92123",'notes'=>'Contact: Mike Alberga'),

    // ── UTILITIES & PHONE ─────────────────────────────────────────────────
    array('name'=>'Comcast Business','category'=>'Utilities','phone'=>'1-800-391-3000',
        'account_no'=>'8771 20 063 0608160','login'=>'accounting@boomerts.com','secret'=>'Digital01',
        'website'=>'https://businessclass.comcast.net',
        'notes'=>"Router: 10.1.10.1 — user cusadmin / pw highspeed\nStatic IP: 24.15.162.8"),

    array('name'=>'Vonage Business','category'=>'Utilities','login'=>'boomerts','secret'=>'W!rel3s$',
        'website'=>'https://my.vonagebusiness.com/',
        'notes'=>"Call forwarding: 2nd tab → Phone Systems → click 301 → scroll to Never Miss a Call → Call Forwarding → Save."),

    array('name'=>'Star2Star Phone System','category'=>'Utilities','phone'=>'(847) 999-8939',
        'secret'=>'dillon109','website'=>'https://www.star2star.com',
        'notes'=>'Contact: Lee Harris'),

    array('name'=>'SBC Ameritech','category'=>'Utilities','login'=>'boomerts@sbcglobal.net','secret'=>'uyh287',
        'notes'=>"Previous password: KqK352\n4-digit code: 0000"),

    array('name'=>'ComEd','category'=>'Utilities','phone'=>'1-877-426-6331','account_no'=>'4563169473'),

    array('name'=>'Metronet','category'=>'Utilities','notes'=>'No details on the old sheet.'),

    array('name'=>'Suburban Propane','category'=>'Utilities','phone'=>'815-459-0909'),

    array('name'=>'Waste Management / Veolia','category'=>'Utilities','phone'=>'800-778-7652',
        'address'=>"8246 Innovation Way\nChicago, IL 60682-0082",'notes'=>'Contact: Greg Lower'),

    array('name'=>'ADT — DVR','category'=>'Utilities','login'=>'admin','secret'=>'Boomer012',
        'notes'=>"Second login: bt2-admin / 1234\nVerbal password: Boomer"),

    // ── FINANCIAL ─────────────────────────────────────────────────────────
    array('name'=>'QuickBooks GoPayment','category'=>'Financial','login'=>'boomertee@me.com','secret'=>'Boomer$1234'),

    array('name'=>'QuickBooks Support Plan','category'=>'Financial','phone'=>'888-222-1276',
        'account_no'=>'SBL43329816',
        'notes'=>"Call ID 1-3472051999\nPlan dates on the old sheet: 02-24-2011 to 03-24-2011 — long expired."),

    array('name'=>'Capital One','category'=>'Financial','phone'=>'1-800-955-7070',
        'address'=>"PO Box 85147\nRichmond, VA 23276"),

    array('name'=>'West Suburban Bank','category'=>'Financial'),

    array('name'=>'Grange Insurance','category'=>'Financial','phone'=>'800-425-1100',
        'address'=>"PO Box 182479\nColumbus, OH 43218-2479"),

    array('name'=>'US Treasury','category'=>'Financial'),

    // ── INTERNAL ──────────────────────────────────────────────────────────
    array('name'=>"Boomer T's FTP",'category'=>'Internal','login'=>'boomerts','secret'=>'boomerts',
        'website'=>'ftp://ftp.boomerts.com'),

    array('name'=>"Digital's FTP",'category'=>'Internal','login'=>'artwork','secret'=>'Digital2009',
        'notes'=>'The D in Digital is capitalised.'),

    array('name'=>'Spiritwear Site Admin','category'=>'Internal','login'=>'spiritwear','secret'=>'Sw351gn5',
        'website'=>'http://216.122.105.137/admin'),

    );
}
