<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Promotion;

class PromotionSeeder extends Seeder
{
    public function run(): void
    {
        Promotion::updateOrCreate(
            ['title' => 'Bienvenue sur Kêkênon !'],
            [
                'description' => 'Profitez de 20% de réduction sur votre première course. Utilisez le code BIENVENUE20 lors de votre prochaine commande.',
                'image_url' => 'https://images.unsplash.com/photo-1449965408869-eaa3f722e40d?w=800&q=80',
                'link_url' => null,
                'is_active' => true,
            ]
        );

        Promotion::updateOrCreate(
            ['title' => 'Parrainez vos amis'],
            [
                'description' => 'Invitez un ami et recevez 500 FCFA sur votre Kêkênon Wallet. Votre ami reçoit aussi 500 FCFA !',
                'image_url' => 'https://images.unsplash.com/photo-1556742049-0cfed4f6a45d?w=800&q=80',
                'link_url' => null,
                'is_active' => true,
            ]
        );
    }
}
