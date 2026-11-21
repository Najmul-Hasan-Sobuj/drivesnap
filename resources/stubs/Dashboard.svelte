<script>
import { onMount } from 'svelte';
import { mountDashboard } from '__IMPORT__';
import '../../drivesnap/dashboard.css';
let { drivesnap } = $props();
let root;
onMount(() => mountDashboard(root, drivesnap));
</script>
<svelte:head><title>DriveSnap backups</title></svelte:head>
<div bind:this={root} class="drivesnap"><p role="status">Loading backup dashboard…</p></div>
