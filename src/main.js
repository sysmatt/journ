import { mount } from 'svelte';
import App from './App.svelte';
import PublicDashboard from './PublicDashboard.svelte';
import './app.css';

// /dashboard is a distinct, unauthenticated route (see
// docs/spec/ui-ux.md § Public dashboard) — decided once here at initial
// load, never client-side routed against the authenticated app, so a
// single page load only ever mounts one or the other.
const Root = location.pathname === '/dashboard' ? PublicDashboard : App;

const app = mount(Root, {
  target: document.getElementById('app')
});

export default app;
