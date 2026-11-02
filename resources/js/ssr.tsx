import { createInertiaApp } from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import { renderToString } from 'react-dom/server';

const pages = import.meta.glob<{ default: React.ComponentType }>('./pages/**/*.tsx');

createServer((page) => createInertiaApp({
  page,
  render: renderToString,
  resolve: async (name) => {
    const component = pages[`./pages/${name}.tsx`];
    if (!component) throw new Error(`Missing page: ${name}`);
    return (await component()).default;
  },
  setup: ({ App, props }) => <App {...props} />,
}));
