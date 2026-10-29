<?php
declare(strict_types=1);
namespace App\Controller\Admin;
use App\Entity\Invoice;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class InvoicesController extends AbstractController
{
    #[AdminRoute(path: '/factures', name: 'invoices', options: ['methods'=>['GET']])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $query = $em->getRepository(Invoice::class)->createQueryBuilder('i');
        $search = mb_substr(trim($request->query->getString('q')), 0, 100);
        if ($search !== '') $query->andWhere('i.searchText LIKE :search')->setParameter('search', '%'.mb_strtolower($search).'%');
        foreach (['from', 'to'] as $key) {
            $value = $request->query->getString($key);
            if ($value === '') continue;
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Paris'));
            if (!$date || $date->format('Y-m-d') !== $value) throw $this->createNotFoundException('Date invalide.');
            $query->andWhere('i.issuedAt '.($key === 'from' ? '>=' : '<').' :'.$key)->setParameter($key, $key === 'from' ? $date : $date->modify('+1 day'));
        }
        $type = $request->query->getString('type');
        if (in_array($type, ['invoice', 'credit'], true)) $query->andWhere('i.type = :type')->setParameter('type', $type);
        $total = (int) (clone $query)->select('COUNT(i.id)')->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / 30)); $page = min($pages, max(1, $request->query->getInt('page', 1)));
        $documents = $query->orderBy('i.issuedAt', \SortDirection::Descending)->addOrderBy('i.id', \SortDirection::Descending)->setFirstResult(($page - 1) * 30)->setMaxResults(30)->getQuery()->getResult();
        return $this->render('admin/invoices/index.html.twig', compact('documents', 'search', 'total', 'pages', 'page'));
    }
    #[AdminRoute(path: '/factures/{id}', name: 'invoice', options: ['methods'=>['GET'], 'requirements'=>['id'=>'\d+']])]
    public function show(int $id, EntityManagerInterface $em): Response
    {
        $invoice = $em->find(Invoice::class, $id);
        if (!$invoice) throw $this->createNotFoundException();
        return $this->render('admin/invoices/show.html.twig', ['invoice'=>$invoice, 'document'=>$invoice->getSnapshot()]);
    }
}
