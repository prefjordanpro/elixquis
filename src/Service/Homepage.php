<?php
namespace App\Service;

use App\Entity\HomepageContent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class Homepage
{
    public function __construct(private EntityManagerInterface $em, private RequestStack $requests) {}
    public function content(string $key): string
    {
        $request = $this->requests->getCurrentRequest();
        $values = $request?->attributes->get('_homepage_contents');
        if ($values === null) {
            $values = [];
            foreach ($this->em->getRepository(HomepageContent::class)->findAll() as $entry) {
                $values[$entry->getId()] = $entry->getType() === 'image' ? $entry->getImagePath() : $entry->getValue();
            }
            $request?->attributes->set('_homepage_contents', $values);
        }
        return $values[$key] ?? HomepageContent::DEFAULTS[$key][2] ?? '';
    }
}
