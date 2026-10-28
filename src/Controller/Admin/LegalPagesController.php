<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\{LegalPage, LegalVersion};
use App\Service\{LegalContent, LegalMarkup};
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\TextEditorType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\{FormType, HiddenType, TextType};
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;

#[IsGranted('ROLE_ADMIN')]
final class LegalPagesController extends AbstractController
{
    public function __construct(private LegalContent $content, private LegalMarkup $markup, private EntityManagerInterface $em) {}
    private function page(string $type): LegalPage
    {
        if (!isset(LegalPage::LABELS[$type])) throw $this->createNotFoundException();
        $this->content->initialize();
        return $this->em->find(LegalPage::class, $type);
    }
    #[AdminRoute(path: '/pages-legales', name: 'legal', options: ['methods' => ['GET']])]
    public function index(): Response
    {
        return $this->render('admin/legal/index.html.twig', ['pages' => $this->content->initialize()]);
    }
    #[AdminRoute(path: '/pages-legales/{type}/modifier', name: 'legal_edit', options: ['methods' => ['GET', 'POST']])]
    public function edit(string $type, Request $request): Response
    {
        $page = $this->page($type); $preview = null; $previewTitle = null;
        $form = $this->container->get('form.factory')->createNamedBuilder('legal_document', FormType::class, [
            'title' => $page->getDraftTitle(), 'body' => $page->getDraftContent(), 'revision' => $page->getRevision(),
        ], ['csrf_token_id' => 'legal_draft_'.$type])
            ->add('title', TextType::class, ['label' => 'Titre du document', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 255)]])
            ->add('body', TextEditorType::class, ['label' => 'Contenu du document', 'required' => false, 'empty_data' => '', 'constraints' => [new Assert\Length(max: 200000)]])
            ->add('revision', HiddenType::class, ['constraints' => [new Assert\Positive()]])->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            try {
                if ($request->request->get('draft_action') === 'preview') {
                    $preview = $this->markup->render($data['body']); $previewTitle = $data['title'];
                } else {
                    $this->content->saveDraft($page, $data['title'], $data['body'], (int) $data['revision']);
                    $this->addFlash('success', 'Brouillon enregistré. La version publique reste inchangée.');
                    return $this->redirectToRoute('admin_legal_edit', ['type' => $type]);
                }
            } catch (\Doctrine\ORM\OptimisticLockException) { $form->addError(new FormError('Ce document a été modifié entre-temps. Rechargez la page.')); }
            catch (\DomainException $exception) { $form->addError(new FormError($exception->getMessage())); }
        }
        $response = $this->render('admin/legal/edit.html.twig', ['page' => $page, 'form' => $form->createView(), 'preview' => $preview, 'previewTitle' => $previewTitle]);
        if ($form->isSubmitted() && !$form->isValid()) $response->setStatusCode(422);
        return $response;
    }
    #[AdminRoute(path: '/pages-legales/{type}/apercu', name: 'legal_preview', options: ['methods' => ['GET']])]
    public function preview(string $type): Response
    {
        $page = $this->page($type);
        return $this->render('admin/legal/preview.html.twig', ['page' => $page, 'title' => $page->getDraftTitle(), 'body' => $this->markup->render($page->getDraftContent()), 'version' => null]);
    }
    #[AdminRoute(path: '/pages-legales/{type}/publier', name: 'legal_publish', options: ['methods' => ['POST']])]
    public function publish(string $type, Request $request): Response
    {
        $page = $this->page($type);
        if (!$this->isCsrfTokenValid('legal_publish_'.$type, $request->request->get('_token')) || $request->request->get('confirmed') !== '1') throw $this->createAccessDeniedException('Confirmez la publication.');
        try {
            $this->content->publish($page, $request->request->getInt('revision'), $this->getUser());
            $this->addFlash('success', 'Nouvelle version publiée. La précédente reste disponible dans l’historique.');
        } catch (\DomainException $exception) { $this->addFlash('warning', $exception->getMessage()); }
        return $this->redirectToRoute('admin_legal_edit', ['type' => $type]);
    }
    #[AdminRoute(path: '/pages-legales/{type}/historique', name: 'legal_history', options: ['methods' => ['GET']])]
    public function history(string $type): Response
    {
        $page = $this->page($type);
        return $this->render('admin/legal/history.html.twig', ['page' => $page, 'versions' => $this->em->getRepository(LegalVersion::class)->findBy(['page' => $page], ['number' => 'DESC'])]);
    }
    #[AdminRoute(path: '/pages-legales/{type}/versions/{id}', name: 'legal_version', options: ['methods' => ['GET'], 'requirements' => ['id' => '\d+']])]
    public function version(string $type, int $id): Response
    {
        $version = $this->em->find(LegalVersion::class, $id);
        if (!$version || $version->getPage()->getId() !== $type) throw $this->createNotFoundException();
        return $this->render('admin/legal/preview.html.twig', ['page' => $version->getPage(), 'title' => $version->getTitle(), 'body' => $this->markup->render($version->getContent()), 'version' => $version]);
    }
}
