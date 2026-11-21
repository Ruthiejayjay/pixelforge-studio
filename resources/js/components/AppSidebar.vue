<script setup lang="ts">
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { BarChart3, BookOpen, Sparkles, Wallet } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();
const user = computed(() => page.props.auth.user);

const mainNavItems = computed(() => {
    const items = [
        {
            title: 'Studio',
            url: '/dashboard',
            icon: Sparkles,
        },
        {
            title: 'Credits',
            url: '/credits',
            icon: Wallet,
        },
    ];

    if (user.value?.is_admin) {
        items.push({
            title: 'Admin Report',
            url: '/admin/report',
            icon: BarChart3,
        });
    }

    return items;
});

const footerNavItems: NavItem[] = [
    {
        title: 'Pixelforge API Docs',
        href: 'https://pixelforge.promptedit.dev/docs',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('dashboard')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <div v-if="user" class="mx-2 mb-2 rounded-lg border bg-card p-3 group-data-[collapsible=icon]:hidden">
                <div class="flex items-center justify-between text-xs text-muted-foreground">
                    <span>Credit Balance</span>
                    <Link :href="route('credits.index')" class="font-medium text-primary hover:underline"> Top up </Link>
                </div>
                <div class="mt-1 font-mono text-lg font-semibold tracking-tight" :title="`$${user.balance_exact}`">
                    {{ user.balance_display }}
                </div>
            </div>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
