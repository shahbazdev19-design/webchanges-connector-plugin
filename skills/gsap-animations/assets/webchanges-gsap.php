<?php
/**
 * Plugin Name: Webchanges GSAP Loader
 * Description: Loads GSAP + ScrollTrigger and a declarative [data-anim] driver. Installed by the Webchanges "gsap-animations" skill.
 * Version: 1.0.0
 * Author: Webchanges
 */

if (!defined('ABSPATH')) {
    exit();
}

add_action('wp_enqueue_scripts', static function () {
    // Don't load in the block editor preview or admin.
    if (is_admin()) {
        return;
    }
    wp_enqueue_script('gsap', 'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js', [], '3.12.5', true); // phpcs:ignore PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent -- optional GSAP CDN loader for the animation skill; not a .org-distributed asset
    wp_enqueue_script('gsap-scrolltrigger', 'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js', ['gsap'], '3.12.5', true); // phpcs:ignore PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent -- optional GSAP CDN loader for the animation skill; not a .org-distributed asset

    $driver = <<<'JS'
(function () {
  function init() {
    if (!window.gsap) return;
    gsap.registerPlugin(ScrollTrigger);
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var nodes = document.querySelectorAll('[data-anim]');
    nodes.forEach(function (el) {
      if (el.dataset.wcAnimDone) return;
      el.dataset.wcAnimDone = '1';
      var type = el.getAttribute('data-anim') || 'fade-up';
      var delay = parseFloat(el.getAttribute('data-anim-delay') || '0');
      var dur = parseFloat(el.getAttribute('data-anim-duration') || '0.8');
      var start = el.getAttribute('data-anim-start') || 'top 85%';
      if (reduce) { gsap.set(el, { clearProps: 'all' }); return; }
      var st = { trigger: el, start: start, toggleActions: 'play none none none' };
      // Stagger children when requested.
      if (el.hasAttribute('data-anim-stagger')) {
        var stagger = parseFloat(el.getAttribute('data-anim-stagger') || '0.12');
        gsap.from(el.children, {
          opacity: 0, y: 30, duration: dur, delay: delay,
          stagger: stagger, ease: 'power2.out', scrollTrigger: st
        });
        return;
      }
      var from = { opacity: 0, duration: dur, delay: delay, ease: 'power3.out', scrollTrigger: st };
      switch (type) {
        case 'fade-up': from.y = 40; break;
        case 'fade-down': from.y = -40; break;
        case 'fade-left': from.x = 40; break;
        case 'fade-right': from.x = -40; break;
        case 'zoom-in': from.scale = 0.9; break;
        case 'zoom-out': from.scale = 1.1; break;
        case 'fade': break;
        default: from.y = 40;
      }
      gsap.from(el, from);
    });

    // Parallax: [data-parallax="0.2"] moves element as you scroll.
    document.querySelectorAll('[data-parallax]').forEach(function (el) {
      if (reduce) return;
      var amount = parseFloat(el.getAttribute('data-parallax') || '0.2');
      gsap.to(el, {
        yPercent: -amount * 100,
        ease: 'none',
        scrollTrigger: { trigger: el, start: 'top bottom', end: 'bottom top', scrub: true }
      });
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
JS;
    wp_add_inline_script('gsap-scrolltrigger', $driver);
}, 20);
