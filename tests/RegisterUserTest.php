<?php

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class RegisterUserTest extends WebTestCase
{
    public function testSomething(): void
    {
        /*
        * 1. Créer un faux client (navigateur) pointer vers une URL
        * 2.Remplir les champs formulaire inscription
        * 3. Verifier si message flash ok?
        */

        // 1.
        $client = static::createClient();
        $client->request('GET', '/inscription');

        // 2.
        $client->submitForm('Valider', [
            'register_user[email]' => 'jordan@exemple.fr',
            'register_user[plainPassword][first]' => '123456',
            'register_user[plainPassword][second]' => '123456',
            'register_user[firstname]' => 'Julie',
            'register_user[lastname]' => 'Doe'
        ]);
        
        //FOLLOW redirection
        $this->assertResponseRedirects('/connexion');
        $client->followRedirect();

        // 3.
        $this->assertSelectorExists('div:contains("Votre compte est correctement créé, veuillez vous connecter")');
    }
}
