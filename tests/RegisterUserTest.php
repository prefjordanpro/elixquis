<?php

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class RegisterUserTest extends WebTestCase
{
    public function testRegistration(): void
    {
        /*
        * 1. Créer un faux client (navigateur) pointer vers une URL
        * 2.Remplir les champs formulaire inscription
        * 3. Verifier si message flash ok?
        */

        // 1.
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $em->getConnection()->getParams()['driver']);
        (new \Doctrine\ORM\Tools\SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $client->request('GET', '/inscription');

        // 2.
        $client->submitForm('Créer mon compte', [
            'register_user[email]' => 'jordan@exemple.fr',
            'register_user[plainPassword][first]' => 'MotDePasseSolide123!',
            'register_user[plainPassword][second]' => 'MotDePasseSolide123!',
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
