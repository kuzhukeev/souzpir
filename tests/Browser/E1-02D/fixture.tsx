import React from 'react';
import {createRoot} from 'react-dom/client';
import {createInertiaApp} from '@inertiajs/react';
import '../../../resources/css/app.css';
import OrganizationSelect from '../../../resources/js/pages/Shell/Context/OrganizationSelect';
const props={company:{name:'Союз Пир',role:'Владелец',brands:[{id:'b',name:'Союз Пир'}],lastLogin:null,logoUrl:null},auth:{user:{id:'u',name:'Иван',email:'ivan@example.test'}},organization:null,brand:null,brands:[],modules:{seller:false,vision:false},can:{'profile.own':true},theme:'neutral',translations:{},ziggy:{routes:{}},errors:{},unread:null};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props,url:'/context/organization',version:'fixture',clearHistory:false,encryptHistory:false});document.body.append(script);
void createInertiaApp({resolve:()=>OrganizationSelect,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
