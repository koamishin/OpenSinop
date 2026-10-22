// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';

// https://astro.build/config
export default defineConfig({
    // GitHub Pages project site: https://koamishin.github.io/OpenSinop/
    // Change base to '' when the docs are served from a custom domain or a
    // user/organisation site (https://<user>.github.io/).
    site: 'https://koamishin.github.io',
    base: '/OpenSinop',
    integrations: [
        starlight({
            title: 'OpenSinop',
            description: 'A practical handbook for school finance teams, operators, and contributors.',
            favicon: '/favicon.ico',
            customCss: ['./src/styles/custom.css'],
            disable404Route: true,
            tableOfContents: { minHeadingLevel: 2, maxHeadingLevel: 3 },
            social: [
                {
                    icon: 'github',
                    label: 'GitHub',
                    href: 'https://github.com/koamishin/OpenSinop',
                },
            ],
            editLink: {
                baseUrl:
                    'https://github.com/koamishin/OpenSinop/edit/main/docs/',
            },
            sidebar: [
                {
                    label: 'Start here',
                    items: [
                        { label: 'Welcome', slug: '' },
                        { label: 'How OpenSinop works', slug: 'guides/workflows' },
                        { label: 'Is it right for our school?', slug: 'institution/overview' },
                        { label: 'Current limits', slug: 'institution/safety-readiness' },
                    ],
                },
                {
                    label: 'For finance staff',
                    items: [
                        { label: 'Record and review collections', slug: 'guides/collections' },
                        { label: 'Plan department spending', slug: 'guides/planning' },
                        { label: 'Review a payment', slug: 'guides/payment-approvals' },
                        { label: 'Use policy advice', slug: 'guides/policy-advice' },
                        { label: 'Student payment screens', slug: 'guides/students' },
                        { label: 'Run a small pilot', slug: 'institution/pilot-playbook' },
                    ],
                },
                {
                    label: 'For IT operators',
                    items: [
                        { label: 'Install an instance', slug: 'getting-started/installation' },
                        { label: 'Configure your instance', slug: 'getting-started/configuration' },
                        { label: 'Run background services', slug: 'guides/operations' },
                        { label: 'Manage access and security', slug: 'guides/security' },
                        { label: 'School branding', slug: 'guides/customization' },
                    ],
                },
                {
                    label: 'For developers',
                    collapsed: true,
                    items: [
                        { label: 'Local setup', slug: 'getting-started/local-development' },
                        { label: 'Architecture', slug: 'architecture' },
                        { label: 'Money and evidence', slug: 'guides/core-concepts' },
                        { label: 'Project map', slug: 'guides/directory-structure' },
                        { label: 'Contribute and test', slug: 'guides/development' },
                        { label: 'Extend the application', slug: 'guides/extensibility' },
                        { label: 'Releases and docs publishing', slug: 'guides/ci-cd' },
                    ],
                },
                {
                    label: 'Reference',
                    collapsed: true,
                    items: [
                        { label: 'Terms in plain English', slug: 'reference/glossary' },
                        { label: 'Command reference', slug: 'reference/commands' },
                        { label: 'Security advisories', slug: 'reference/security-advisories' },
                        { label: 'Troubleshooting', slug: 'reference/troubleshooting' },
                        { label: 'Code examples', slug: 'reference/examples' },
                    ],
                },
            ],
        }),
    ],
});
