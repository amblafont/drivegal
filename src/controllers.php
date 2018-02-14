<?php

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Silex\Application;
use Drivegal\GalleryInfo;
use Drivegal\GalleryService;
use Drivegal\Authenticator;
use Drivegal\Exception\AlbumNotFoundException;
use Drivegal\Exception\ServiceAuthException;
use Drivegal\Exception\ServiceException;
use JasonGrimes\Paginator;

//Request::setTrustedProxies(array('127.0.0.1'));

/** @var Application $app */
global $app;

//
// Helper function to convert a route parameter into a gallery.
//
$galleryProvider = function($galleryInfo, Request $request) use ($app) {
    if ($slug = $request->attributes->get('gallery_slug')) {
        $galleryInfo = $app['gallery.info.mapper']->findBySlug($slug);
    } elseif ($id = $request->attributes->get('google_user_id')) {
        $galleryInfo = $app['gallery.info.mapper']->findByGoogleUserId($id);
    }
    if (!$galleryInfo instanceof GalleryInfo) {
        throw new NotFoundHttpException('Gallery "' . ($slug ?: $id) . '" not found.');
    }

    return $galleryInfo;
};

function getDefaultGallery(Application $app) {
        $galleryInfo = new GalleryInfo($app['drivegal.googleUserId'], 'maingallery', $app['drivegal.mainGalleryName']);

        $galleryInfo->setCredentials($app['drivegal.googleCredentials']);
        $galleryInfo->setIsActive(true);
        return $galleryInfo;
}

//
// Error handlers
//
$app->error(function(ServiceAuthException $e, $code) use ($app) {
    return new Response($app['twig']->render('errors/gallery-auth-failed.twig'));
});
$app->error(function (\Exception $e, $code) use ($app) {
    if ($app['debug']) {
        return;
    }

    $message = '';
    switch (get_class($e)) {
        case 'Drivegal\Exception\ServiceException':
            $code = 503;
            $message = 'The Google Drive server returned an error.';
            break;
    }

    // 404.html, or 40x.html, or 4xx.html, or error.html
    $templates = array(
        'errors/'.$code.'.html',
        'errors/'.substr($code, 0, 2).'x.html',
        'errors/'.substr($code, 0, 1).'xx.html',
        'errors/default.html',
    );

    return new Response($app['twig']->resolveTemplate($templates)->render(array('code' => $code, 'message' => $message)), $code);
});

//
// Controller: Home page.
//
$app->get('/', function () use ($app) {
    return $app['twig']->render('accueil.twig', array());
})
->bind('homepage')
;


function renderTopLevelAlbum (Application $app, GalleryInfo $galleryInfo) {
    $albumContents = $app['gallery']->getAlbumContents($galleryInfo, '');
    return $app['twig']->render('album.twig', array(
        'galleryName' => $galleryInfo->getGalleryName(),
        'albumTitle' => 'Album list',
        'subAlbums' => $albumContents->getSubAlbums(),
        'files' => '',
        'breadcrumbs' => $albumContents->getBreadcrumbs(),
        'albumUrl' => $app['url_generator']->generate('default-album-list', array()),
        'streamUrl' => $app['url_generator']->generate('defaultgallery', array()),
        'nextUrl' => '',
        'prevUrl' => '',
        'showLightboxPhoto' => '',
        'gallerySlug' => $galleryInfo->getSlug(),
    ));
}

$app->get('/maingallery/album/', function(Application $app) {
  return renderTopLevelAlbum($app, getDefaultGallery($app));
})
->bind('default-album-list')
;

//
// Controller: View an album in a gallery
//
function renderAlbum(Application $app, GalleryInfo $galleryInfo, $album_path) {
    try {
        /** @var \Drivegal\AlbumContents $albumContents */
        $albumContents = $app['gallery']->getAlbumContents($galleryInfo, $album_path);
    } catch (AlbumNotFoundException $e) {
        $app['session']->getFlashBag()->add('error', 'Album "' . $album_path . '" not found.');
        return $app->redirect($app['url_generator']->generate('gallery', array('gallery_slug' => $galleryInfo->getSlug())));
    }

    return $app['twig']->render('album.twig', array(
        'galleryName' => $galleryInfo->getGalleryName(),
        'albumTitle' => $albumContents->getTitle(),
        'files' => $albumContents->getFiles(),
        'subAlbums' => $albumContents->getSubAlbums(),
        'breadcrumbs' => $albumContents->getBreadcrumbs(),
        'albumUrl' => $app['url_generator']->generate('default-album-list', array()),
        'streamUrl' => $app['url_generator']->generate('defaultgallery', array()),
        'showLightboxPhoto' => '',
        'gallerySlug' => $galleryInfo->getSlug(),
    ));
}

$app->get('/maingallery/album/{album_path}/',
   function (Application $app,  $album_path) {
     return renderAlbum($app, getDefaultGallery($app), $album_path);
   })
// slug can't start with an underscore or contain a slash (we have to specify that manually since we override the default regex).
->assert('album_path', '.*') // album path *can* contain slashes.
->value('album_path', '')
->bind('defaultalbum');


//
// Middleware: Determine the current user.
//
$app->before(function (Symfony\Component\HttpFoundation\Request $request) use ($app) {
    $token = $app['security.token_storage']->getToken();
    // echo '<pre>' . print_r($token, true);
    $app['user'] = null;

    if ($token && !$app['security.trust_resolver']->isAnonymous($token)) {
        $app['user'] = $token->getUser();
    }
});


function renderGalleryPage(Application $app, Request $request, GalleryInfo $galleryInfo, $pagestr) {
    $pg = substr($pagestr, 4); // Strip out the leading "page" string.
    $photoStream = $app['gallery']->getPhotoStream($galleryInfo); /** @var \Drivegal\PhotoStream $photoStream */
    $photoStream->setPerPage(60);
    $page = $photoStream->getPage($pg);
    $paginator = new Paginator(
        $photoStream->count(),
        $photoStream->getPerPage(),
        $pg,
        $app['url_generator']->generate('defaultgallery', array()) . '/page(:num)'
    );

    return $app['twig']->render('stream.twig', array(
        'galleryName' => $galleryInfo->getGalleryName(),
        'gallerySlug' => $galleryInfo->getSlug(),
        'files' => $page->getFiles(),
        'paginator' => $paginator,
        'showLightboxPhoto' => $request->query->get('show'),
    ));
}



$app->get('/maingallery/{pagestr}', function(Application $app, Request $request, $pagestr) {
        return renderGalleryPage($app, $request, getDefaultGallery($app), $pagestr);
})
    ->assert('pagestr', '^page\d+')
    ->value('pagestr', 'page1')
    ->bind('defaultgallery');

