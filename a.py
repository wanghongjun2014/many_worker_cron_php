class User(Model):
    id = IntegerField('id')
    name = StringField('username')
    email = StringField('email')
    password = StringFiled('password')


u = User(id=1234, name='wang', email = '1', password='12x')
u.save()