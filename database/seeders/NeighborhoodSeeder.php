<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Neighborhood;

class NeighborhoodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Default coordinates for cities
        $portoNovoLat = 6.4969;
        $portoNovoLng = 2.6283;

        $cotonouLat = 6.3667;
        $cotonouLng = 2.4333;

        $calaviLat = 6.4481;
        $calaviLng = 2.3533;

        $neighborhoods = [
            // === PORTO-NOVO ===
            // 1er Arrondissement
            ['name' => 'Accron-Gogankomey', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Adjègounlè', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Adomey', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Ahouantikomey', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Akpassa Odo Oba', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Avassa Bagoro Agbokomey', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Ayétoro', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Ayimlonfidé', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Déguèkomè', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Dota-Attingbansa-Azonzakomey', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Ganto', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Gbassou-Itabodo', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Gbêcon', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Guévié-Zinkomey', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Hondji-Honnou Filla', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Houègbo-Hlinkomey', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Houéyogbé-Gbèdji', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Houèzounmey', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Idi-Araba', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Iléfiè', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Kpota Sandodo', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Lokossa', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Oganla-Gare-Est', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Sadognon-Adjégounlè', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Sadognon-Woussa', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Sagbo Kossoukodé', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Sokomey-Toffinkomey', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Togoh – Adankomey', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283, 'aliases' => 'Togoh,Adankomey'],
            ['name' => 'Vêkpa', 'arrondissement' => '1er Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],

            // 2e Arrondissement
            ['name' => 'Agbokou Aga', 'arrondissement' => '2e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Agbokou Bassodji Mairie', 'arrondissement' => '2e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283, 'aliases' => 'Agbokou Mairie,Bassodji'],
            ['name' => 'Agbokou Centre social', 'arrondissement' => '2e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283, 'aliases' => 'Agbokou Centre'],
            ['name' => 'Agbokou Odo', 'arrondissement' => '2e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Attakè Olory-Togbé', 'arrondissement' => '2e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283, 'aliases' => 'Attakè,Olory Togbé'],
            ['name' => 'Attakè Yidi', 'arrondissement' => '2e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Djègan Daho', 'arrondissement' => '2e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Donoukin Lissèssa', 'arrondissement' => '2e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283, 'aliases' => 'Donoukin,Lissèssa'],
            ['name' => 'Gbèzounkpa', 'arrondissement' => '2e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Guévié Djèganto', 'arrondissement' => '2e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283, 'aliases' => 'Guévié,Djèganto'],
            ['name' => 'Hinkoudé', 'arrondissement' => '2e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Kandévié Radio Hokon', 'arrondissement' => '2e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283, 'aliases' => 'Kandévié Radio,Radio Hokon'],
            ['name' => 'Koutongbé', 'arrondissement' => '2e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Sèdjèko', 'arrondissement' => '2e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Tchinvié', 'arrondissement' => '2e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Zounkpa Houèto', 'arrondissement' => '2e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283, 'aliases' => 'Zounkpa,Houèto'],

            // 3e Arrondissement
            ['name' => 'Adjina Nord', 'arrondissement' => '3e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Adjina Sud', 'arrondissement' => '3e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Avakpa Kpodji', 'arrondissement' => '3e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283, 'aliases' => 'Avakpa,Kpodji'],
            ['name' => 'Avakpa-Tokpa', 'arrondissement' => '3e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283, 'aliases' => 'Avakpa Tokpa'],
            ['name' => 'Djassin Daho', 'arrondissement' => '3e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Djassin Zounmè', 'arrondissement' => '3e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Foun-Foun Djaguidi', 'arrondissement' => '3e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283, 'aliases' => 'Foun Foun,Djaguidi'],
            ['name' => 'Foun-Foun Gbègo', 'arrondissement' => '3e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283, 'aliases' => 'Foun Foun Gbègo'],
            ['name' => 'Foun-Foun Sodji', 'arrondissement' => '3e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283, 'aliases' => 'Foun Foun Sodji'],
            ['name' => 'Foun-Foun Tokpa', 'arrondissement' => '3e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283, 'aliases' => 'Foun Foun Tokpa'],
            ['name' => 'Hassou Agué', 'arrondissement' => '3e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Oganla Atakpamè', 'arrondissement' => '3e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Oganla Nord', 'arrondissement' => '3e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Oganla Poste', 'arrondissement' => '3e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Oganla Sokè', 'arrondissement' => '3e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Oganla Sud', 'arrondissement' => '3e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Ouinlinda Aholoukomey', 'arrondissement' => '3e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283, 'aliases' => 'Ouinlinda,Aholoukomey'],
            ['name' => 'Ouinlinda Hôpital', 'arrondissement' => '3e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283, 'aliases' => 'Ouinlinda Hopital'],
            ['name' => 'Zèbou Aga', 'arrondissement' => '3e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Zèbou Ahouangbo', 'arrondissement' => '3e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Zèbou–Itatigri', 'arrondissement' => '3e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283, 'aliases' => 'Zèbou Itatigri'],
            ['name' => 'Zèbou–Massè', 'arrondissement' => '3e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283, 'aliases' => 'Zèbou Massè'],

            // 4e Arrondissement
            ['name' => 'Anavié', 'arrondissement' => '4e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Anavié Voirie', 'arrondissement' => '4e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Djègan kpèvi', 'arrondissement' => '4e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283, 'aliases' => 'Djègan Kpèvi'],
            ['name' => 'Dodji', 'arrondissement' => '4e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Gbèdjromèdé Fusion', 'arrondissement' => '4e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283, 'aliases' => 'Gbèdjromèdé'],
            ['name' => 'Gbodjè', 'arrondissement' => '4e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Guévié', 'arrondissement' => '4e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Hlogou', 'arrondissement' => '4e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283, 'aliases' => 'Hlongou'],
            ['name' => 'Houinmè Château d\'eau', 'arrondissement' => '4e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283, 'aliases' => 'Houinmè Chateau d eau'],
            ['name' => 'Houinmè Djaguidi', 'arrondissement' => '4e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Houinmè Ganto', 'arrondissement' => '4e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Houinmè Gbèdjromèdé', 'arrondissement' => '4e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Hounsa', 'arrondissement' => '4e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Hounsouko', 'arrondissement' => '4e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Kandévié Missogbé', 'arrondissement' => '4e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283, 'aliases' => 'Kandévié,Missogbé'],
            ['name' => 'Kandévié Owodé', 'arrondissement' => '4e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283, 'aliases' => 'Kandévié,Owodé'],
            ['name' => 'Kpogbonmè', 'arrondissement' => '4e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Sèto–Gbodjè', 'arrondissement' => '4e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283, 'aliases' => 'Sèto Gbodjè,Sèto'],

            // 5e Arrondissement
            ['name' => 'Akonaboè', 'arrondissement' => '5e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Djlado', 'arrondissement' => '5e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Dowa', 'arrondissement' => '5e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Dowa Aliogbogo', 'arrondissement' => '5e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Dowa Dédomè', 'arrondissement' => '5e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Houinvié', 'arrondissement' => '5e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Louho', 'arrondissement' => '5e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Ouando', 'arrondissement' => '5e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4850, 'lng' => 2.6350],
            ['name' => 'Ouando Clékanmè', 'arrondissement' => '5e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283, 'aliases' => 'Ouando Clekanme'],
            ['name' => 'Ouando Kotin', 'arrondissement' => '5e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Tokpota Dadjrougbé', 'arrondissement' => '5e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283, 'aliases' => 'Tokpota,Dadjrougbé'],
            ['name' => 'Tokpota Davo', 'arrondissement' => '5e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Tokpota Vèdo', 'arrondissement' => '5e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Tokpota Zèbè', 'arrondissement' => '5e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],
            ['name' => 'Tokpota Zinlivali', 'arrondissement' => '5e Arrondissement', 'city' => 'Porto-Novo', 'lat' => 6.4969, 'lng' => 2.6283],

            // === COTONOU ===
            ['name' => 'Akpakpa', 'arrondissement' => 'Akpakpa', 'city' => 'Cotonou', 'lat' => 6.3750, 'lng' => 2.4550],
            ['name' => 'Fidjrossè', 'arrondissement' => 'Fidjrossè', 'city' => 'Cotonou', 'lat' => 6.3610, 'lng' => 2.3780, 'aliases' => 'Fidjrossé,Fidjrossè Centre,Fidjrossè Plage'],
            ['name' => 'Cadjèhoun', 'arrondissement' => 'Cadjèhoun', 'city' => 'Cotonou', 'lat' => 6.3570, 'lng' => 2.4100, 'aliases' => 'Cadjéhoun'],
            ['name' => 'Gbégamey', 'arrondissement' => 'Gbégamey', 'city' => 'Cotonou', 'lat' => 6.3630, 'lng' => 2.4130, 'aliases' => 'Gbegamey'],
            ['name' => 'Saint Michel', 'arrondissement' => 'Saint Michel', 'city' => 'Cotonou', 'lat' => 6.3650, 'lng' => 2.4320, 'aliases' => 'St Michel'],
            ['name' => 'Ganhi', 'arrondissement' => 'Ganhi', 'city' => 'Cotonou', 'lat' => 6.3580, 'lng' => 2.4360],
            ['name' => 'Zogbohouè', 'arrondissement' => 'Zogbo', 'city' => 'Cotonou', 'lat' => 6.3880, 'lng' => 2.4040, 'aliases' => 'Zogbohoué,Zogbo'],
            ['name' => 'Sainte Rita', 'arrondissement' => 'Ste Rita', 'city' => 'Cotonou', 'lat' => 6.3820, 'lng' => 2.4180, 'aliases' => 'Ste Rita,Sainte-Rita'],
            ['name' => 'Agla', 'arrondissement' => 'Agla', 'city' => 'Cotonou', 'lat' => 6.3830, 'lng' => 2.3920, 'aliases' => 'Agla Petit-Château,Agla Les Pylônes'],
            ['name' => 'Menontin', 'arrondissement' => 'Menontin', 'city' => 'Cotonou', 'lat' => 6.3770, 'lng' => 2.3850, 'aliases' => 'Ménontin'],
            ['name' => 'Sikècodji', 'arrondissement' => 'Sikècodji', 'city' => 'Cotonou', 'lat' => 6.3730, 'lng' => 2.4280, 'aliases' => 'Sikecodji,Sikècodji Cabinet'],
            ['name' => 'Kouhounou', 'arrondissement' => 'Kouhounou', 'city' => 'Cotonou', 'lat' => 6.3780, 'lng' => 2.4010, 'aliases' => 'Stade de l\'Amitié,Kouhounou Stade'],
            ['name' => 'Zongo', 'arrondissement' => 'Zongo', 'city' => 'Cotonou', 'lat' => 6.3630, 'lng' => 2.4340],
            ['name' => 'Haie Vive', 'arrondissement' => 'Haie Vive', 'city' => 'Cotonou', 'lat' => 6.3550, 'lng' => 2.3980, 'aliases' => 'La Haie Vive'],
            ['name' => 'Cocotiers', 'arrondissement' => 'Cocotiers', 'city' => 'Cotonou', 'lat' => 6.3540, 'lng' => 2.4040, 'aliases' => 'Les Cocotiers,Zone Résidentielle'],
            ['name' => 'Jonquet', 'arrondissement' => 'Jonquet', 'city' => 'Cotonou', 'lat' => 6.3640, 'lng' => 2.4270],
            ['name' => 'Jericho', 'arrondissement' => 'Jericho', 'city' => 'Cotonou', 'lat' => 6.3730, 'lng' => 2.4180, 'aliases' => 'Jéricho'],
            ['name' => 'Hindé', 'arrondissement' => 'Hindé', 'city' => 'Cotonou', 'lat' => 6.3810, 'lng' => 2.4270, 'aliases' => 'Hinde'],
            ['name' => 'Segbeya', 'arrondissement' => 'Segbeya', 'city' => 'Cotonou', 'lat' => 6.3840, 'lng' => 2.4500, 'aliases' => 'Sègbèya'],
            ['name' => 'Ladji', 'arrondissement' => 'Ladji', 'city' => 'Cotonou', 'lat' => 6.3930, 'lng' => 2.4450],
            ['name' => 'Midombo', 'arrondissement' => 'Midombo', 'city' => 'Cotonou', 'lat' => 6.3770, 'lng' => 2.4640],
            ['name' => 'Yénawa', 'arrondissement' => 'Yénawa', 'city' => 'Cotonou', 'lat' => 6.3750, 'lng' => 2.4610, 'aliases' => 'Yenawa'],
            ['name' => 'Guinkomey', 'arrondissement' => 'Guinkomey', 'city' => 'Cotonou', 'lat' => 6.3600, 'lng' => 2.4300, 'aliases' => 'Guinkomé'],
            ['name' => 'Placodji', 'arrondissement' => 'Placodji', 'city' => 'Cotonou', 'lat' => 6.3510, 'lng' => 2.4350],
            ['name' => 'Dantokpa', 'arrondissement' => 'Dantokpa', 'city' => 'Cotonou', 'lat' => 6.3700, 'lng' => 2.4390, 'aliases' => 'Grand Marché Dantokpa,Tokpa'],
            ['name' => 'Vossa', 'arrondissement' => 'Vossa', 'city' => 'Cotonou', 'lat' => 6.3890, 'lng' => 2.4220],
            ['name' => 'Sènadé', 'arrondissement' => 'Sènadé', 'city' => 'Cotonou', 'lat' => 6.3850, 'lng' => 2.4700, 'aliases' => 'Senade'],

            // === ABOMEY-CALAVI ===
            ['name' => 'Calavi Kpota', 'arrondissement' => 'Calavi', 'city' => 'Abomey-Calavi', 'lat' => 6.4460, 'lng' => 2.3550, 'aliases' => 'Kpota,Calavi Kpota'],
            ['name' => 'Godomey', 'arrondissement' => 'Godomey', 'city' => 'Abomey-Calavi', 'lat' => 6.3830, 'lng' => 2.3480, 'aliases' => 'Godomey Togoudo,Godomey-Gare'],
            ['name' => 'Tankpè', 'arrondissement' => 'Calavi', 'city' => 'Abomey-Calavi', 'lat' => 6.4350, 'lng' => 2.3470, 'aliases' => 'Tankpe'],
            ['name' => 'Togoudo', 'arrondissement' => 'Godomey', 'city' => 'Abomey-Calavi', 'lat' => 6.4250, 'lng' => 2.3490],
            ['name' => 'Hevié', 'arrondissement' => 'Hevié', 'city' => 'Abomey-Calavi', 'lat' => 6.4170, 'lng' => 2.2610, 'aliases' => 'Hêvié,Hevie'],
            ['name' => 'Ouèdo', 'arrondissement' => 'Ouèdo', 'city' => 'Abomey-Calavi', 'lat' => 6.4710, 'lng' => 2.2780, 'aliases' => 'Ouedo'],
            ['name' => 'Akassato', 'arrondissement' => 'Akassato', 'city' => 'Abomey-Calavi', 'lat' => 6.5310, 'lng' => 2.3430],
            ['name' => 'Zogbadjè', 'arrondissement' => 'Calavi', 'city' => 'Abomey-Calavi', 'lat' => 6.4520, 'lng' => 2.3420, 'aliases' => 'Zogbadje,Zone UAC'],
            ['name' => 'Agori', 'arrondissement' => 'Calavi', 'city' => 'Abomey-Calavi', 'lat' => 6.4420, 'lng' => 2.3510],
            ['name' => 'Bidossessi', 'arrondissement' => 'Godomey', 'city' => 'Abomey-Calavi', 'lat' => 6.3980, 'lng' => 2.3390, 'aliases' => 'Bidosséssi'],
            ['name' => 'Glo-Djigbé', 'arrondissement' => 'Glo-Djigbé', 'city' => 'Abomey-Calavi', 'lat' => 6.6340, 'lng' => 2.2850, 'aliases' => 'Glo,Glo-Djigbe'],
            ['name' => 'Zinvié', 'arrondissement' => 'Zinvié', 'city' => 'Abomey-Calavi', 'lat' => 6.6110, 'lng' => 2.3270, 'aliases' => 'Zinvie'],
            ['name' => 'Atrokpocodji', 'arrondissement' => 'Godomey', 'city' => 'Abomey-Calavi', 'lat' => 6.4010, 'lng' => 2.3210, 'aliases' => 'Atrokpo-Codji'],
        ];

        foreach ($neighborhoods as $data) {
            Neighborhood::updateOrCreate(
                ['name' => $data['name'], 'city' => $data['city']],
                array_merge([
                    'country' => 'Bénin',
                    'lat' => $data['lat'],
                    'lng' => $data['lng'],
                    'aliases' => $data['aliases'] ?? null,
                    'is_active' => true,
                ], $data)
            );
        }

        $this->command->info('Seeded ' . count($neighborhoods) . ' neighborhoods (Cotonou, Abomey-Calavi, Porto-Novo).');
    }
}
