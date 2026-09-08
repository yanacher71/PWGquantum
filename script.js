/* =========================================================================
   PWG QUANTUM — SITE SCRIPT
   Vanilla JS, no dependencies. Handles: mobile nav, header scroll state,
   active nav-link tracking, subtle scroll-reveal, footer year, and
   fixed-header-aware smooth scrolling for in-page links.
   ========================================================================= */

(function () {
  'use strict';

  var header = document.getElementById('site-header');
  var navToggle = document.getElementById('nav-toggle');
  var mainNav = document.getElementById('main-nav');
  var navLinks = mainNav ? mainNav.querySelectorAll('a[href^="#"]') : [];
  var footerYear = document.getElementById('footer-year');

  /* ---------- Footer year ---------- */
  if (footerYear) {
    footerYear.textContent = new Date().getFullYear();
  }

  /* ---------- Mobile nav toggle ---------- */
  if (navToggle && mainNav) {
    navToggle.addEventListener('click', function () {
      var isOpen = mainNav.classList.toggle('is-open');
      navToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      navToggle.setAttribute('aria-label', isOpen ? 'Close menu' : 'Open menu');
    });

    // Close the mobile menu after a nav link is chosen
    navLinks.forEach(function (link) {
      link.addEventListener('click', function () {
        mainNav.classList.remove('is-open');
        navToggle.setAttribute('aria-expanded', 'false');
        navToggle.setAttribute('aria-label', 'Open menu');
      });
    });
  }

  /* ---------- Header shadow/border on scroll ---------- */
  function updateHeaderState() {
    if (window.scrollY > 8) {
      header.classList.add('is-scrolled');
    } else {
      header.classList.remove('is-scrolled');
    }
  }
  updateHeaderState();
  window.addEventListener('scroll', updateHeaderState, { passive: true });

  /* ---------- Smooth scroll with fixed-header offset ---------- */
  var headerHeight = header ? header.offsetHeight : 0;

  document.querySelectorAll('a[href^="#"]').forEach(function (link) {
    link.addEventListener('click', function (e) {
      var targetId = link.getAttribute('href');
      if (!targetId || targetId === '#') return;
      var target = document.querySelector(targetId);
      if (!target) return;

      e.preventDefault();
      var top = target.getBoundingClientRect().top + window.pageYOffset - headerHeight + 1;
      window.scrollTo({ top: top, behavior: 'smooth' });

      // Keep the URL hash in sync without an extra jump
      history.pushState(null, '', targetId);
    });
  });

  /* ---------- Active nav-link tracking via IntersectionObserver ---------- */
  var sections = ['products', 'applications', 'technology', 'mission', 'portal', 'contact']
    .map(function (id) { return document.getElementById(id); })
    .filter(Boolean);

  if ('IntersectionObserver' in window && sections.length) {
    var navLinkMap = {};
    navLinks.forEach(function (link) {
      navLinkMap[link.getAttribute('href')] = link;
    });

    var sectionObserver = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        var id = '#' + entry.target.id;
        var link = navLinkMap[id];
        if (!link) return;
        if (entry.isIntersecting) {
          navLinks.forEach(function (l) { l.classList.remove('is-active'); });
          link.classList.add('is-active');
        }
      });
    }, { rootMargin: '-45% 0px -50% 0px', threshold: 0 });

    sections.forEach(function (section) { sectionObserver.observe(section); });
  }

  /* ---------- Subtle one-time scroll reveal for major sections ---------- */
  var revealTargets = document.querySelectorAll(
    '#products .section-head, #applications .section-head, #technology-inner, ' +
    '.mission-statement, #portal .portal-copy, #contact .section-head'
  );

  if ('IntersectionObserver' in window && revealTargets.length) {
    revealTargets.forEach(function (el) { el.classList.add('reveal'); });

    var revealObserver = new IntersectionObserver(function (entries, obs) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          obs.unobserve(entry.target);
        }
      });
    }, { threshold: 0.2 });

    revealTargets.forEach(function (el) { revealObserver.observe(el); });
  } else {
    // No IntersectionObserver support: show content immediately
    revealTargets.forEach(function (el) { el.classList.add('is-visible'); });
  }

})();
