<?php
namespace App\Service;

use App\Entity\HomePageContent;
use Doctrine\ORM\EntityManagerInterface;

final class HomeContent
{
    public function __construct(private EntityManagerInterface $em) {}

    public function get(): HomePageContent
    {
        return $this->em->find(HomePageContent::class, HomePageContent::ID) ?? new HomePageContent();
    }

    public function initialize(): HomePageContent
    {
        $content = $this->get();
        if (!$this->em->contains($content)) {
            $this->em->persist($content);
            $this->em->flush();
        }
        return $content;
    }
}
