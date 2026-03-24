<?php

/**
 * @file
 * Create benchmark test nodes with varying numbers of RHDS elements.
 *
 * Run via: drush php:eval "$(cat benchmarks/create-nodes.php)"
 */

use Drupal\node\Entity\Node;

/**
 * Element snippets representing realistic RHDS usage patterns.
 *
 * These mirror how a Drupal content author or Layout Builder would use
 * RHDS elements in page content.
 */
$elements = [
  '<rh-card>
    <h3 slot="header">Product Feature</h3>
    <p>Red Hat OpenShift provides a consistent hybrid cloud foundation for building and scaling containerized applications.</p>
    <rh-cta slot="footer"><a href="/products/openshift">Learn more</a></rh-cta>
  </rh-card>',

  '<rh-alert state="info">
    <h4 slot="header">System update</h4>
    <p>Scheduled maintenance window: Saturday 2:00 AM - 4:00 AM EST.</p>
  </rh-alert>',

  '<rh-badge number="42"></rh-badge>',

  '<rh-blockquote color-palette="lightest">
    <p slot="quote">Open source is the foundation of innovation in enterprise technology.</p>
    <span slot="author">Red Hat Summit 2025</span>
  </rh-blockquote>',

  '<rh-cta variant="primary"><a href="/contact">Contact sales</a></rh-cta>',

  '<rh-stat>
    <span slot="title">Global customers</span>
    <span slot="statistic">90%</span>
    <span slot="description">of Fortune 500 companies trust Red Hat</span>
  </rh-stat>',

  '<rh-tabs>
    <rh-tab slot="tab">Overview</rh-tab>
    <rh-tab-panel>
      <p>Enterprise Kubernetes platform with full-stack automated operations.</p>
    </rh-tab-panel>
    <rh-tab slot="tab">Features</rh-tab>
    <rh-tab-panel>
      <p>Built-in monitoring, logging, CI/CD pipelines, and service mesh.</p>
    </rh-tab-panel>
    <rh-tab slot="tab">Pricing</rh-tab>
    <rh-tab-panel>
      <p>Contact us for enterprise pricing and support options.</p>
    </rh-tab-panel>
  </rh-tabs>',

  '<rh-tag color="red">Featured</rh-tag>',

  '<rh-accordion>
    <rh-accordion-header>
      <h3>What is included in a subscription?</h3>
    </rh-accordion-header>
    <rh-accordion-panel>
      <p>Access to all platform features, 24/7 support, security updates, and certified container images.</p>
    </rh-accordion-panel>
    <rh-accordion-header>
      <h3>How do I get started?</h3>
    </rh-accordion-header>
    <rh-accordion-panel>
      <p>Sign up for a free trial or contact our sales team for a guided evaluation.</p>
    </rh-accordion-panel>
  </rh-accordion>',

  '<rh-surface color-palette="darker">
    <div style="padding: 32px;">
      <h3>Ready to get started?</h3>
      <p>Try Red Hat OpenShift free for 60 days.</p>
      <rh-cta variant="secondary"><a href="/trial">Start free trial</a></rh-cta>
    </div>
  </rh-surface>',

  '<rh-timestamp date="2026-03-24T12:00:00Z" date-format="long"></rh-timestamp>',

  '<rh-card>
    <h3 slot="header">Case Study</h3>
    <p>See how a major financial services company migrated 500 applications to OpenShift in 18 months.</p>
    <rh-cta slot="footer"><a href="/success-stories">Read the case study</a></rh-cta>
  </rh-card>',

  '<rh-alert state="success">
    <h4 slot="header">Deployment complete</h4>
    <p>Your application has been successfully deployed to production.</p>
  </rh-alert>',

  '<rh-stat>
    <span slot="title">Uptime SLA</span>
    <span slot="statistic">99.95%</span>
    <span slot="description">Guaranteed availability</span>
  </rh-stat>',
];

$sizes = [
  ['Benchmark: Small (5 elements)', 5],
  ['Benchmark: Medium (100 elements)', 100],
  ['Benchmark: Large (1000 elements)', 1000],
  ['Benchmark: XL (2000 elements)', 2000],
];

foreach ($sizes as [$title, $count]) {
  $body = '';
  for ($i = 0; $i < $count; $i++) {
    $body .= $elements[$i % count($elements)] . "\n";
  }

  $node = Node::create([
    'type' => 'page',
    'title' => $title,
    'body' => [
      'value' => $body,
      'format' => 'full_html',
    ],
    'status' => 1,
  ]);
  $node->save();
  echo "Created node/{$node->id()}: $title\n";
}
