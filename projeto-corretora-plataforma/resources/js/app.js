import { Input } from 'postcss';
import './bootstrap';
import axios from 'axios';
import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

axios.defaults.headers.common['X-CSRF-TOKEN'] =
    document.querySelector('meta[name="csrf-token"]').content;

let Senha = document.getElementById("password");
let Senha2 = document.getElementById("password-conf");



Senha.addEventListener('input', function(){
    this.type = 'text';
    clearTimeout(this._timer);

    this._timer = setTimeout(() =>{
        this.type = 'password';

    }, 2000);
})
Senha2.addEventListener('input', function(){
    this.type = 'text';
    clearTimeout(this._timer);

    this._timer = setTimeout(() =>{
        this.type = 'password';

    }, 2000);
})

Senha.addEventListener('blur', function(){
    this.type = 'password';
});
Senha2.addEventListener('blur', function(){
    this.type = 'password';
});
